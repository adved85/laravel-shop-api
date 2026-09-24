# Health Checks — Liveness & Readiness

| Probe | Question | Response | Status |
|---|---|---|---|
| `/health/live` | Is Laravel alive? | `{"status":"ok"}` | `200` |
| `/health/ready` | Can it reach Postgres, Redis, RabbitMQ? | see below | `200` / `503` |
| `php artisan health:check` | Same as `/health/ready`, no HTTP needed | prints each check | exit `0` / `1` |

```json
{ "status": "ok", "checks": { "database": "ok", "redis": "ok", "rabbitmq": "ok" } }
```

All three share one class — `App\Services\ReadinessChecker` — so "ready" is defined
in exactly one place. The controller and the console command are thin wrappers.

---

## 1. Why two probes, not one

They trigger different reactions, so conflating them causes real damage:

| | Failing means | So it must |
|---|---|---|
| **Liveness** | restart the container | depend on **nothing** external |
| **Readiness** | stop routing traffic here, don't restart | check every dependency |

If liveness touched the database, a database outage would restart every healthy API
container — repeatedly — turning one outage into two. That isn't hypothetical: the first
version of these routes had exactly that bug (§3).

`/health/ready` returns **`503`**, not `200`-with-error-body. That status *is* the
contract: it's what makes `curl -f`, `wget --spider`, and orchestrators treat the instance
as unhealthy. A readiness endpoint that always returns `200` is decorative.

---

## 2. Where these are wired today

Two repos, three consumers. `shop-infrastructure` owns the compose stack.

| Consumer | Uses | Why that one |
|---|---|---|
| `api`'s Docker healthcheck | `php artisan health:check` | The container is **php-fpm only** — no HTTP listener inside it, so it cannot probe itself over HTTP |
| `proxy`'s Docker healthcheck | `wget --spider /nginx-health` | Deliberately nginx's *own* endpoint, so nginx's health reflects nginx — not its backends |
| `/health/live`, `/health/ready` | routed, no automated caller yet | Reachable through nginx via `fastcgi_pass`, but loopback-only for now |

That first row is the non-obvious one. A Docker healthcheck runs **inside** the container
it checks (same mechanism as `docker exec`), and `api` listens on **9000 speaking
FastCGI**, not HTTP. So `curl -f http://localhost/health/live` there connects to nothing —
not a hostname problem, an "in this container nothing serves HTTP" problem. The console
command sidesteps it entirely: no HTTP, no port, no hostname for reaching itself.

```yaml
api:
  healthcheck:
    test: ["CMD", "php", "artisan", "health:check"]
    interval: 30s
    timeout: 10s        # must exceed ReadinessChecker's 3s per-dependency timeout
    retries: 3
    start_period: 30s
```

### The HTTP routes are reachable, but not public

nginx routes both probes to `api:9000`, restricted to loopback:

```nginx
location = /health/ready {
    allow 127.0.0.1;  allow ::1;  deny all;
    fastcgi_pass api:9000;
    fastcgi_read_timeout 5s;
}
```

So today they're for manual use from inside the proxy:

```bash
docker compose exec proxy wget -qO- http://localhost/health/ready
```

Their first automated caller will be `healthcheck.sh` / `deploy.sh` — "did this deploy
succeed?" is a readiness question, and asking it *through the proxy* exercises the whole
chain (nginx → FastCGI → Laravel → Postgres/Redis/RabbitMQ) rather than asking one
container about itself. An external uptime monitor or load balancer is the same shape,
but would need that loopback ACL relaxed first.

### Timeouts are layered on purpose

Each level must allow the one below it to answer, or you get a truncated failure instead
of a useful one:

```
ReadinessChecker   3s per dependency   ← fails a hung dependency cleanly
nginx              5s read timeout     ← lets Laravel answer 503 instead of nginx saying 504
compose healthcheck 10s                ← lets the whole command finish
```

---

## 3. Why the routes bypass all middleware

Registered in `bootstrap/app.php` → `routes/health.php` with **no middleware group**:

```php
then: function () {
    Route::group([], base_path('routes/health.php'));
},
```

This fixes a real bug. The routes originally lived in `routes/web.php`, which applies the
`web` group — including `StartSession`. With `SESSION_DRIVER=database`, **every probe hit
queried Postgres**, so the liveness endpoint silently depended on the database: exactly the
restart-loop described in §1. `route:list` now shows `(none)` for both, and a test locks
that in (§6).

> The `api` group would have been safe — in Laravel 11+ it's only `SubstituteBindings`
> (`throttle:api` was removed from the default). It would just force an `/api` prefix and
> couple probes to whatever that group grows later. Staying outside `/api/*` also keeps
> them clear of `shouldRenderJsonWhen(fn ($r) => $r->is('api/*'))`, so a probe can never
> come back wrapped in the API error envelope.

---

## 4. What each check actually does

One rule governs all three: **a health check must do real I/O to the dependency.**
Anything that only inspects local PHP state reports on this process, not on the thing
being checked. Each choice below was verified by breaking it, not by reading docs.

### Database — `DB::connection()->getPdo()`

Laravel connects **lazily**: building a connection object opens no socket. So the
intuitive calls silently check nothing.

| Call | Touches the server? | Detects an outage? |
|---|---|---|
| `DB::getConnections()` | ❌ | ❌ |
| `DB::connection()` | ❌ | ❌ |
| `DB::connection()->getPdo()` | ✅ | ✅ |

Proven against a deliberately broken connection (valid config, port 9999, nothing
listening):

```
DB::connection("broken")  → returned an object, no exception
DB::getConnections()      → ["broken"]      ← would report HEALTHY
$c->getPdo()              → SQLSTATE[08006] connection refused   ← correct
```

On a fresh boot `getConnections()` returns `[]`, so it also reports *unhealthy on a healthy
app*. Wrong in both directions.

`DB::select('select 1')` is a stricter option — it proves the connection can execute a
statement, catching things like revoked permissions — at the cost of a real query.
`getPdo()` catches the outage cases readiness exists for.

### Redis — `Redis::connection()->ping()`

Deliberately **not** `Cache::put()/get()`. `Cache` is an abstraction over whichever store
`CACHE_STORE` names; point it at `array`, `file`, or `database` and the check stops testing
Redis at all:

```
Redis unreachable (port 9999):
  Cache check, CACHE_STORE=array → ok      ← FALSE POSITIVE
  Redis::ping()                  → error   ← correct
```

Not hypothetical, twice over: `phpunit.xml` pins `CACHE_STORE=array`, so a cache-based
probe passes trivially in tests; and this project already hit it once — the first
verification of the predis swap used `Cache::put()/get()`, reported success, and Redis had
received nothing, because the round-trip completed in-process.

`PING` is also Redis's own liveness command: it round-trips to the server and **writes
nothing**. A probe running every 30s forever shouldn't write.

### RabbitMQ — open a real connection

AMQP has no ping, so the honest check is open-then-close — no channel, nothing published:

```php
$connection = new AMQPStreamConnection(
    $host['host'], $host['port'], $host['user'], $host['password'], $host['vhost'],
    insist: false, login_method: 'AMQPLAIN', locale: 'en_US',
    connection_timeout: 3.0,
    read_write_timeout: 3.0,
);
$connection->close();
```

**The timeouts are load-bearing.** They're parameters 10 and 11 of that constructor, hence
the named arguments — otherwise every preceding parameter would have to be passed
positionally just to reach them. Without them a *hung* broker (reachable, not answering)
hangs the probe forever, and a probe that hangs is worse than one that fails: the
orchestrator just waits.

**Where the credentials come from.** `config('queue.connections.rabbitmq.hosts.0')` — a key
that doesn't exist in `config/queue.php`. The queue package injects it at boot:

```php
// vendor/vladimir-yuldashev/laravel-queue-rabbitmq/src/LaravelQueueRabbitMQServiceProvider.php
$this->mergeConfigFrom(__DIR__.'/../config/rabbitmq.php', 'queue.connections.rabbitmq');
```

`mergeConfigFrom` loads a config file and grafts it onto the config tree under that key. Its
values are `env()` wrappers, so reading config — rather than hardcoding — guarantees the
probe uses **the same settings the queue itself uses** and can't drift into reporting "ok"
for a broker the jobs aren't talking to.

Two caveats: `mergeConfigFrom` never overwrites existing keys (hand-write a `rabbitmq` key
into `config/queue.php` and yours wins), and it's skipped when config is already cached —
fine here, because `docker/entrypoint.sh` runs `config:cache` at *container start*, after
compose has injected the env vars.

---

## 5. Failure reasons are logged, never returned

```php
catch (Throwable $e) {
    Log::warning("Health check failed: {$name}", ['exception' => $e->getMessage()]);
    return 'error';
}
```

Connection exceptions carry hostnames, ports, usernames, sometimes credentials. The caller
gets `"error"`; the detail goes to `storage/logs/laravel.log`.

The routes are currently loopback-only at the nginx layer, so this is defence in depth
rather than the only safeguard — which is the point. The ACL is infrastructure config in
another repo and could be relaxed the day someone wires up an external monitor; the app
shouldn't start leaking internals when that happens.

`Throwable`, not `Exception`, so PHP `Error`s (missing class, type error) are caught too —
a probe must never 500 on its own.

**No rate limiting**, also deliberate: Laravel's throttler is cache-backed, so a throttled
readiness endpoint dies exactly when Redis does. Rate-limit at nginx if these ever go
public.

---

## 6. Tests

`tests/Feature/HealthEndpointsTest.php` — 5 tests, no real services required. They swap a
fake `ReadinessChecker` into the container, so what's under test is the *endpoint
contract*, not whether infrastructure happens to be up:

- `/health/live` → `200` + exactly `{"status":"ok"}`
- `/health/ready` → `200` when all checks pass
- `/health/ready` → **`503`** when one fails
- `/health/live` stays `200` while readiness fails — the §1 separation
- both routes have **no middleware** — regression guard for the §3 bug

Whether Postgres/Redis/RabbitMQ are genuinely reachable is runtime state, not a code
property — that's what `/health/ready` is *for*. It was verified by hand, by stopping each
container in turn:

```
all up            → {"status":"ok", ...}                              200
rabbitmq stopped  → {"status":"error","checks":{"rabbitmq":"error"}}  503, curl -f exit 22
                  → /health/live STILL 200        ← no pointless restart
redis stopped     → only "redis" flipped
rabbitmq back     → recovered in ~3s
```

Each dependency was stopped **individually**, to confirm each check exercises its own
service rather than passing by coincidence.

---

## Quick reference

- [ ] Liveness touches **nothing** external — it decides whether to restart
- [ ] Readiness must answer **503** when unhealthy — `curl -f` / `wget --spider` decide
      their exit code from the HTTP status alone, so a `200` carrying
      `{"status":"error"}` still reads as healthy
- [ ] Check dependencies **directly** (`getPdo()`, `ping()`, a real connection) — never an
      abstraction (`Cache`) or local state (`getConnections()`)
- [ ] Always set connect/read timeouts — a hung dependency must fail, not hang
- [ ] Keep outer timeouts above inner ones, or you truncate the real answer
- [ ] Log failure reasons; don't return them
- [ ] Don't `curl http://localhost` inside `api` — it has no HTTP server
- [ ] Verify by stopping each dependency in turn, not by reading the code
