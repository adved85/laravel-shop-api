# Docker Image & Release Pipeline — Big Picture

> **How the Dockerfile, `ci.yml`, and `docker-publish.yml` fit together, and
> what actually happens between "I pushed a tag" and "a version is running
> somewhere."**

This is the connective-tissue doc. For the line-by-line YAML walkthrough of
each workflow, see the sibling files in
[`docker_image_and_pipelines/`](docker_image_and_pipelines/):
[`ci.md`](docker_image_and_pipelines/ci.md) and
[`docker_publish.md`](docker_image_and_pipelines/docker_publish.md). This
doc covers what those two don't: the Dockerfile itself, how it connects to
the workflows that build it, and what a git tag actually does.

If a term below (workflow, job, step, runner, action, package) is unfamiliar,
[`github_actions_and_packages_glossary.md`](docker_image_and_pipelines/github_actions_and_packages_glossary.md)
defines it in one line.

---

## 1. The three pieces, in one picture

Where these actually live in the repo:

```text
laravel-shop-api/
├── app/
├── routes/
├── tests/
├── Dockerfile
├── docker/                     ← see §4 for what's inside
├── .dockerignore
└── .github/
    └── workflows/
        ├── ci.yml
        └── docker-publish.yml
```

```text
   Dockerfile              docker/                 .github/workflows/
   ─────────────           ─────────                ──────────────────
   turns source code       small config files       decide WHEN to build,
   into a runnable          COPY'd in by the          and WHAT TO DO with
   image                    Dockerfile's last          the result
                             stage
```

```text
git push (branch)  ──▶  ci.yml            "does the code work, and does
                          │                 the image still build?"
                          ▼
                     (nothing published)

git push (tag v1.2.3) ──▶ docker-publish.yml   "re-run ci.yml as a gate,
                                                 then push the image to GHCR"
```

**The one thing to hold onto from this whole doc:** publishing an image to
GHCR and *running* that image somewhere are two separate, manual acts. Every
piece described here stops at "the image is sitting in the registry."
Nothing in this repo deploys it anywhere — see §5.

---

## 2. How the Dockerfile and the workflows actually connect

Neither `ci.yml` nor `docker-publish.yml` contains the word "Dockerfile"
anywhere in their YAML. No step says `file: Dockerfile`. So what makes them
build it?

```yaml
- uses: docker/build-push-action@v6
  with:
    context: .
```

`context: .` means "the repo root is the build context." Neither workflow
sets a `file:` input, so the underlying build tool falls back to its own
default — confirmed directly from the tool itself:

```text
$ docker buildx build --help
  -f, --file string   Name of the Dockerfile (default: "PATH/Dockerfile")
```

So the chain is:

```text
actions/checkout@v4   →  puts the whole repo (Dockerfile included) onto
                          the runner's disk

context: .             →  "build from the current directory"

no file: input given   →  buildx defaults to ./Dockerfile
```

There is exactly one `Dockerfile` in this repo, and both workflows resolve
it the same implicit way — so **`ci.yml`'s `docker-build` job and
`docker-publish.yml`'s `build-and-push` job build the identical file.** Not
a copy, the same instructions, run twice. That's *why* `ci.yml` bothers
building the image at all on an ordinary push (see
[`ci.md`](docker_image_and_pipelines/ci.md) §10–14): it's a dry run of the
exact same build the release pipeline will do for real. If it builds and
passes there, that's real evidence it'll build during a release too — not a
guess, because nothing about *how* the build happens differs.

One more shared bit of state: both jobs set `cache-from`/`cache-to:
type=gha`. Buildx's cache keys come from the Dockerfile's own stages, so
layers `ci.yml` built on a normal push are already sitting in GitHub's cache
when `docker-publish.yml` runs later, and get reused instead of rebuilt.

The `docker/` directory (`entrypoint.sh`, `opcache.ini`, `zzz-app.conf`)
sits one level deeper still — the workflows never touch it directly. It's
pulled in by `COPY` lines *inside* the Dockerfile (see §4).

---

## 3. The Dockerfile — four stages

```text
base  →  deps  →  build  →  runtime
                    (deps and build are discarded; only "runtime" ships)
```

Every `FROM` starts a **brand-new filesystem**, not a continuation of the
previous stage. Look at what each stage's parent actually is:

```dockerfile
FROM php:${PHP_VERSION}-fpm-alpine AS base
FROM base AS deps
FROM deps AS build
FROM base AS runtime          # ← parent is base, NOT build
```

`runtime` never inherits anything `deps` or `build` did — installing
Composer, running `composer install`, copying in the app — except the one
line that reaches back on purpose:

```dockerfile
COPY --from=build --chown=www-data:www-data /var/www/html /var/www/html
```

That's the *only* bridge. Everything else `build` contained stays behind in
a stage that ships nowhere. Proof, not just theory — building all four
stages separately and measuring them:

| stage | size | what it adds |
|---|---|---|
| `base` | 158MB | PHP + extensions |
| `deps` | 197MB | +Composer binary, +`vendor/` |
| `build` | 199MB | +app source, +optimized autoloader |
| `runtime` | 186MB | app code only — **smaller than `build`** |

```text
$ docker run --rm --entrypoint sh stage-build:demo -c "which composer"
/usr/bin/composer                    ← present in build

$ docker run --rm --entrypoint sh stage-runtime:demo -c "which composer"
composer: not found                  ← gone from runtime
```

`runtime` ends up smaller than `build` despite shipping the same app code,
because it never inherited Composer or whatever `composer install` left
behind — that history simply isn't part of its lineage.

**Why bother:** the shipped image carries no dependency manager, no build
tools, nothing an attacker could use if they got a shell — not because it
was deleted afterward, but because it was **never present** in that stage's
history to begin with.

### What each stage does

- **`base`** — installs every PHP extension the app needs (`gd`,
  `pdo_pgsql`, `pgsql`, `opcache`, `bcmath`, `pcntl`, `exif`, `zip`, `intl`)
  via `install-php-extensions`, a helper script copied in from
  `mlocati/php-extension-installer`. It handles Alpine's build-dependency
  dance (install a compiler, compile, remove the compiler) so nothing here
  needs hand-written `apk add` incantations.
- **`deps`** — copies in *only* `composer.json`/`composer.lock` (not the app
  yet) and runs `composer install`. This ordering is the entire point of
  splitting it from `build`: Docker's layer cache is keyed on what changed.
  Edit a controller without touching `composer.lock`, and this whole layer
  is reused — no re-run of `composer install`.
- **`build`** — *now* copies in the rest of the app (`COPY . .`) and
  generates an optimized, "authoritative" autoloader.
- **`runtime`** — builds on `base`, pulls in only the finished
  `/var/www/html` from `build`, drops to `USER www-data`, and sets the
  `ENTRYPOINT`/`CMD`.

---

## 4. `docker/` — the three support files

```text
docker/
├── php/
│   └── opcache.ini
├── php-fpm.d/
│   └── zzz-app.conf
└── entrypoint.sh
```

These are referenced by `COPY` lines inside the Dockerfile's `runtime`
stage — the workflows never see them directly.

**`docker/php/opcache.ini`**
The one setting worth understanding: `opcache.validate_timestamps=0`.
Normally opcache checks a file's mtime on every request — correct for a
live server where files get edited in place. Wasted work here, since a
Docker image is immutable: the only way code changes is a whole new image
being deployed. Turning the check off is a real, free speed gain
*specifically because* this runs in a container, not on a traditional host.

**`docker/php-fpm.d/zzz-app.conf`**

```ini
listen = 0.0.0.0:9000
clear_env = no
```

Two defaults that would otherwise fail silently:
- `listen` — php-fpm defaults to `127.0.0.1:9000`, unreachable from the
  `nginx` container in the infra repo, which needs the Docker network.
- `clear_env` — php-fpm wipes the process environment by default before
  handing off to PHP. Without this, `DB_HOST`, `REDIS_HOST`, etc. set via
  compose's `environment:` would simply never reach `env()` in Laravel.
  Confirmed by direct testing, not a theoretical concern.

> **Filename matters here.** This file must sort alphabetically *after*
> the base image's own `zz-docker.conf`, which sets `daemonize = no`.
> Naming it `zz-docker.conf` (matching, not extending) would **replace**
> that file instead of layering on top of it — php-fpm would daemonize,
> and the container would exit at code 0 right after logging "ready to
> handle connections." This exact failure was reproduced and diagnosed
> before the fix (rename to `zzz-app.conf`) was found.

**`docker/entrypoint.sh`**

```sh
php artisan config:cache
php artisan route:cache
php artisan event:cache
exec "$@"
```

Runs once per **container start**, not once per image build. Caching at
build time would bake in empty values — real config (which host is
`redis`, which is `postgres`) only exists once compose starts the container
with real env vars. `exec "$@"` replaces the shell process with `php-fpm`
rather than forking a child, so `php-fpm` ends up as PID 1, as Docker
expects.

---

## 5. `ci.yml` and `docker-publish.yml` — the short version

Full walkthroughs live in
[`ci.md`](docker_image_and_pipelines/ci.md) and
[`docker_publish.md`](docker_image_and_pipelines/docker_publish.md). In
brief:

- **`ci.yml`** runs on every push/PR: tests against a real
  `postgres:16-alpine` service container (not `phpunit.xml`'s SQLite
  default — verified directly that env vars really do override it), plus a
  `docker-build` job that builds the Dockerfile and checks it stays sane
  (right extensions present, php-fpm stays in the foreground).
- **`docker-publish.yml`** runs only on a version-tag push. Its `verify`
  job is literally `uses: ./.github/workflows/ci.yml` — it calls the other
  file rather than re-describing the same steps, so the two can't drift
  apart the way `composer.json`'s PHP version and an earlier, separately
  written CI matrix once did. `build-and-push` only starts once `verify`
  succeeds, then pushes the image to GHCR.

---

## 6. Git tags, semver, and deployment

### A tag is a pointer, not an action

`git tag v1.2.0` marks "this exact commit is version 1.2.0" — cheaper and
more permanent than a branch, and unlike a branch, it doesn't move as you
keep committing. By itself, this command touches nothing but your local
repo.

### `git push origin v1.2.0` pushes the tag — nothing else

This was worth verifying directly rather than assuming. Setup: a commit
already on the remote's `main`, then a *second* commit made locally only
(never pushed), tagged `v1.2.0`.

```text
BEFORE pushing the tag:
    remote main   → commit A          (unchanged, as expected)
    local main    → commit B  ← tag v1.2.0   (not on remote yet)

AFTER `git push origin v1.2.0`:
    remote main        → commit A      ← still unchanged!
    remote refs/tags/v1.2.0 → commit B ← new
```

So a tag push:
- Creates/updates exactly **one** ref — the tag. Never touches any branch.
- Transfers whatever commit/tree/blob objects that tag needs *if the remote
  doesn't already have them* — which is why commit B's content genuinely
  arrived on the remote even though no branch carried it there.
- **Never includes uncommitted changes.** `git tag` points at whatever
  commit is currently `HEAD` — it doesn't stage or snapshot your working
  directory.

> **Practical consequence:** if you tag and push a tag without first
> pushing the branch, the release still builds correctly (Actions checks
> out the exact tagged commit, not "whatever `main` currently is") — but
> `main` on GitHub will look older than what the tag claims to ship. Push
> your branch *before* you tag. See §7 step 2.

### One tag, multiple Docker tags

```text
git tag v1.2.0   →   pushed image tagged:   1.2.0
                                             1.2
                                             latest   (skipped for a
                                                        pre-release like
                                                        v1.2.0-rc1)
```

`1.2.0` is exact. `1.2` always points at the newest patch in that minor
line. `latest` always points at the newest *stable* release. (The exact
YAML that derives these is in
[`docker_publish.md`](docker_image_and_pipelines/docker_publish.md) §10.)

### Publishing ≠ deploying

The infra repo's `compose.yml` uses:

```yaml
image: ghcr.io/adved85/laravel-shop-api:${API_VERSION}
```

Whatever `API_VERSION` is set to in *that* repo's `.env` is what actually
runs. **Nothing here pushes a new version onto a running system just
because it landed in GHCR.** That's a separate, manual step — §7.

### One more gotcha: package visibility

The first time `docker-publish.yml` pushes an image, GHCR can create the
package as **private**, tied to your account. If whatever later runs
`docker compose pull` isn't authenticated, that pull gets denied. Worth
checking the package's visibility at
`github.com/adved85?tab=packages` after the very first release and
flipping it to public if needed — better to know before it surprises you.

---

## 7. Release lifecycle, end to end

1. Work on `main`, merge PRs. `ci.yml` runs on all of it, keeping `main`
   known-good. Nothing is published to GHCR yet.

2. **Commit and push the actual code first.** The tag has to point at a
   commit the remote already has, or `main` and the release drift out of
   sync (§6):
   ```
   git commit -m "L-CI-GHCR: created docker image and release pipeline"
   git push origin L-CI-GHCR
   ```
   > Always tag *after* pushing, never before.

3. Decide a commit is release-worthy:
   ```
   git tag v1.2.0            # created locally
   git push origin v1.2.0
   ```

4. That push triggers `docker-publish.yml`. It re-verifies, builds, and
   pushes `1.2.0`, `1.2`, and `latest` to GHCR.

5. **Separately, manually,** in the `shop-infrastructure` repo:
   ```
   # edit .env:  API_VERSION=1.2.0
   docker compose pull
   docker compose up -d
   ```
   This is the actual deploy step, and it lives entirely outside this repo.
   Deliberate, not missing automation — there's no production server yet,
   so there's nothing to auto-deploy *to*. Wiring an automatic deploy later
   is a separate decision for when a real server exists.

---

## Quick reference — cutting a release

- [ ] Commit and push the actual code changes — **before** tagging
- [ ] Confirm `main` is green (`ci.yml` passing) on what you just pushed
- [ ] Decide the version number (`MAJOR.MINOR.PATCH`, semver rules)
- [ ] `git tag vX.Y.Z`
- [ ] `git push origin vX.Y.Z`
- [ ] Watch `docker-publish.yml` — `verify` job, then `build-and-push` job
- [ ] Confirm the tag appears at
      `github.com/adved85/laravel-shop-api/pkgs/container/laravel-shop-api`
- [ ] First release only: check the package isn't stuck on private
- [ ] In `shop-infrastructure`: set `API_VERSION=X.Y.Z` in `.env`
- [ ] `docker compose pull && docker compose up -d`
