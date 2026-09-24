# laravel-shop-api

Backend REST API for **abc-shop** — an e-commerce platform built with Laravel 13,
PostgreSQL, Redis, RabbitMQ and Laravel Sanctum.

It ships as a container image to GHCR and runs as the `api` service of the
[shop-infrastructure](https://github.com/adved85/shop-infrastructure) compose stack,
alongside nginx, the React frontend, Postgres, Redis and RabbitMQ.

---

## What's Inside

Six parts, each with a full write-up in [`app/docs/guide/`](app/docs/guide/):

| # | Part | In one line |
|---|---|---|
| [1](#1-unified-api-response--error-handling) | **Unified API Response** | Every response — success, validation error, exception, 404 — has one JSON shape |
| [2](#2-api-versioning-structure) | **API Versioning** | Routes, controllers, resources and requests isolated per version; adding v2 never touches v1 |
| [3](#3-authentication) | **Authentication** | Sanctum bearer tokens guarding the admin endpoints |
| [4](#4-backing-services--redis--rabbitmq) | **Backing Services** | Redis as cache, RabbitMQ for Laravel queues *and* cross-language messaging |
| [5](#5-health-probes) | **Health Probes** | Liveness vs. readiness, consumed by the compose stack's healthchecks |
| [6](#6-container-image-ci--releases) | **Image, CI & Releases** | Multi-stage build, tests against real Postgres, tag-triggered publish to GHCR |

Then: [Local Development](#local-development) · [How This Was Built](#how-this-was-built) ·
[Project Structure](#project-structure)

---

## Stack

- **Laravel 13** · **PHP 8.4** · **PostgreSQL**
- **Redis** — cache (via `predis`, no C extension) · **RabbitMQ** — queues & messaging
- **Laravel Sanctum** — token-based API authentication
- **Devilbox** — local development environment (unrelated to how it runs in production)

---

## 1. Unified API Response & Error Handling

Every response — success, validation failure, exception, or 404 — returns the same JSON
shape, from every layer of the application.

```json
{ "success": true,  "message": "Created", "code": 201, "data": {} }
{ "success": false, "message": "Validation failed", "code": 422, "errors": {} }
```

Controllers use a dedicated `ApiResponse` factory:

```php
$this->apiResponse->ok($data)               // 200
$this->apiResponse->created($resource)      // 201
$this->apiResponse->paginated($page, ResourceClass::class)  // 200 + pagination
$this->apiResponse->noContent()             // 204
$this->apiResponse->notFound('message')     // 404
$this->apiResponse->unauthenticated()       // 401
$this->apiResponse->validationError($msg, $errors)  // 422
```

Three layers, working in order:

| Layer | File | Handles |
|-------|------|---------|
| FormRequest | `app/Http/Requests/ApiFormRequests.php` | Validation & authorization failures |
| Response factory | `app/Support/API/ApiResponse.php` | All controller responses |
| Global handler | `bootstrap/app.php` | Uncaught exceptions (404, 401, 500, …) |

→ [Full documentation](app/docs/guide/unified_api_response_system.md)

---

## 2. API Versioning Structure

Routes, controllers, resources and requests are versioned and fully isolated — adding v2
never touches v1. Models and migrations are shared, because a version change is about *how
data is exposed*, not what it is.

Built so far, the admin surface:

```
routes/admin/v1.php                            →  /api/admin/v1/...
app/Http/Controllers/Admin/V1/CategoryController.php
app/Http/Resources/Admin/V1/CategoryResource.php
app/Http/Requests/Admin/V1/CategoryRequest.php
```

The same pattern extends to the public API (`routes/api/v1.php` → `/api/v1/...`) and to a
future v2, which slots in beside V1 rather than modifying it.

→ [Full documentation](app/docs/guide/api_versioning_structure.md)

---

## 3. Authentication

Admin endpoints are protected with Sanctum token authentication.

| Endpoint | Method | Auth |
|----------|--------|------|
| `/api/admin/login` | `POST` | — |
| `/api/admin/register` | `POST` | — |
| `/api/admin/logout` | `POST` | Bearer token |
| `/api/admin/v1/*` | any | Bearer token |

---

## 4. Backing Services — Redis & RabbitMQ

**Redis** backs the cache, through **`predis`** (a pure-PHP client) rather than the
`phpredis` C extension — so the production image needs no extra extension, and local and
production run the exact same client. Laravel puts cache keys on Redis DB `1`, separate
from DB `0`, so a `Cache::flush()` can't wipe unrelated keys.

**RabbitMQ** serves two different jobs over one broker, kept on separate exchanges:

| Use | Package | Payload |
|---|---|---|
| Laravel queues (`dispatch()`, `queue:work`) | `vladimir-yuldashev/laravel-queue-rabbitmq` | serialized PHP job — Laravel only |
| Cross-service messaging (future Go/Rust consumers) | `php-amqplib/php-amqplib` | plain JSON — any language |

They must not share a queue: a Go consumer reading Laravel's job queue gets unreadable
serialized PHP, and a Laravel worker reading plain JSON can't build a Job from it.

---

## 5. Health Probes

Three probes, one shared `ReadinessChecker` — so "ready" is defined in exactly one place:

| Probe | Answers | Response |
|---|---|---|
| `/health/live` | is Laravel alive? | `{"status":"ok"}` — always `200` |
| `/health/ready` | can it reach its backing services? | `200`, or **`503`** if any fail |
| `php artisan health:check` | same as `/health/ready`, without HTTP | exit `0` / `1` |

```json
{ "status": "ok", "checks": { "database": "ok", "redis": "ok", "rabbitmq": "ok" } }
```

Split on purpose: **liveness failing means restart the container**, so it touches nothing
external — otherwise a database blip would restart every healthy container in a loop.
**Readiness failing means stop sending traffic, don't restart.** Readiness returns `503`
specifically so `curl -f` and `wget --spider` fail, which is what an orchestrator reacts to.

Both routes are registered in `routes/health.php` with **no middleware** — under the `web`
group they'd start a database-backed session, making even liveness depend on Postgres.

In `shop-infrastructure`, the `api` container's healthcheck runs **`php artisan
health:check`**, because that container is php-fpm only and has no HTTP listener to probe
itself over. The HTTP routes are wired through nginx via `fastcgi_pass` but restricted to
loopback, so today they're for manual checks
(`docker compose exec proxy wget -qO- http://localhost/health/ready`).

→ [Full documentation](app/docs/guide/health_checks.md)

---

## 6. Container Image, CI & Releases

Every push and PR runs the test suite (against a real PostgreSQL service container, not
SQLite) plus a Dockerfile build-and-sanity-check, via GitHub Actions. Cutting a version tag
builds a production image — multi-stage, `php-fpm-alpine`, no nginx baked in — and publishes
it to GHCR:

```yaml
api:
  image: ghcr.io/adved85/laravel-shop-api:${API_VERSION}
```

**Cutting a release:**

```bash
git push origin <branch>   # push code first — a tag must point at a pushed commit
git tag v1.2.0
git push origin v1.2.0     # triggers docker-publish.yml: tests → build → push to GHCR
```

Then, **separately**, in `shop-infrastructure`: bump `API_VERSION` and
`docker compose pull && docker compose up -d`. Publishing an image and deploying it are two
different acts — this repo only does the first.

→ [Full documentation](app/docs/guide/docker_image_and_release_pipeline.md)
· line-by-line: [ci.yml](app/docs/guide/docker_image_and_pipelines/ci.md),
[docker-publish.yml](app/docs/guide/docker_image_and_pipelines/docker_publish.md),
[terms glossary](app/docs/guide/docker_image_and_pipelines/github_actions_and_packages_glossary.md)

---

## Local Development

```bash
# start Devilbox
docker-compose up -d
./shell.sh

# install dependencies
composer install

# migrate & seed
php artisan migrate
php artisan db:seed

# verify routes
php artisan route:list

# run the test suite
php artisan test
```

Local URL: `http://laravel-shop-api.dvl.to:88`

> Port 88 is used instead of 80 — configured in `devilbox/.env`:
> `HOST_PORT_HTTPD=88`

Devilbox is only a local convenience. It is **not** how this app runs in production — that's
the `shop-infrastructure` compose stack, pulling the published image from GHCR.

---

## How This Was Built

The app was built up in layers, each mirrored by a git branch/PR and a write-up in
[`app/docs/guide/`](app/docs/guide/). Read them in this order:

| # | Layer | Docs | Branch |
|---|-------|------|--------|
| 1 | Sanctum auth + the unified API response system — every response, success or failure, in one JSON shape | [unified_api_response_system.md](app/docs/guide/unified_api_response_system.md) | [L1](https://github.com/adved85/laravel-shop-api/tree/L1) |
| 2 | Versioned API structure — isolated routes, controllers, resources — plus the *Category* resource and its feature tests | [api_versioning_structure.md](app/docs/guide/api_versioning_structure.md) | [L2](https://github.com/adved85/laravel-shop-api/tree/L2) |
| 3 | Admin V1 Brand endpoints, and a `Concern` shared by Brand + Category computing their sort `order` in code instead of a static DB default — plus the `refresh()`-after-`create()` bug that exposed | [admin_v1_brand_resource.md](app/docs/guide/admin_v1_brand_resource.md) + [explicit_incremental_ordering.md](app/docs/guide/explicit_incremental_ordering.md) | [L3](https://github.com/adved85/laravel-shop-api/tree/L3) |
| 4 | 🧩 Docker image, GitHub CI & the GHCR release pipeline — multi-stage build, tests against real Postgres, tag-triggered publishing | [docker_image_and_release_pipeline.md](app/docs/guide/docker_image_and_release_pipeline.md) + [line-by-line](app/docs/guide/docker_image_and_pipelines/) | [L4](https://github.com/adved85/laravel-shop-api/tree/L4) |
| 5 | 🧩 Action version bumps across both workflows, and the pinning policy behind them | [glossary § pinning](app/docs/guide/docker_image_and_pipelines/github_actions_and_packages_glossary.md) | [L6](https://github.com/adved85/laravel-shop-api/tree/L6) |
| 6 | 🧩 Backing services & health probes — Redis cache via predis, RabbitMQ queues + cross-language messaging, `/health/live`, `/health/ready`, `php artisan health:check` | [health_checks.md](app/docs/guide/health_checks.md) | `L7` *(in progress)* |

Each doc explains the *why* behind that layer — decisions made, bugs hit — not just the
*what*. The infrastructure side of the same story lives in
[shop-infrastructure/docs](https://github.com/adved85/shop-infrastructure/tree/main/docs).

---

## Project Structure

🧩 marks a directory or file **deliberately added** for this project's architecture — as
opposed to Laravel's default skeleton, left unmarked:

```text
laravel-shop-api/
├── app/
│   ├── Http/
│   │   ├── Controllers/
│   │   │   ├── Admin/V1/             🧩 versioned controllers — v2 slots in beside V1
│   │   │   └── HealthController.php  🧩 liveness + readiness, HTTP side
│   │   ├── Requests/
│   │   │   ├── ApiFormRequests.php   🧩 shared base — validation failures → standard envelope
│   │   │   └── Admin/V1/             🧩 versioned form requests
│   │   └── Resources/Admin/V1/       🧩 versioned resources — control exactly what JSON goes out
│   ├── Console/Commands/
│   │   └── HealthCheckCommand.php    🧩 readiness without HTTP — `php artisan health:check`
│   ├── Services/
│   │   └── ReadinessChecker.php      🧩 the one place "is this instance ready" is defined
│   ├── Models/Concerns/
│   │   └── HasComputedOrder.php      🧩 computes `order` in code, shared by Brand & Category
│   ├── Support/API/
│   │   └── ApiResponse.php           🧩 the one place every response shape gets decided
│   └── docs/                         🧩 guide/ = written-up decisions · planing/ = raw notes
│
├── routes/
│   ├── api.php
│   ├── admin/v1.php                  🧩 versioned route file
│   └── health.php                    🧩 probes — registered with NO middleware on purpose
│
├── tests/Feature/                    🧩 one file per resource + health endpoint coverage
│
├── Dockerfile                        🧩 multi-stage: base → deps → build → runtime
├── docker/                           🧩 php-fpm pool config, opcache tuning, entrypoint
├── .github/workflows/                🧩 ci.yml (tests + image check), docker-publish.yml (→ GHCR)
│
├── database/{factories,migrations,seeders}/
├── config/  ·  public/  ·  resources/  ·  storage/  ·  bootstrap/
```
