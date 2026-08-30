# Laravel CI Explanation
```yaml
 path: (`.github/workflows/ci.yml`)
 ```

Unfamiliar with "job", "step", "runner", or "action"? See
[`github_actions_and_packages_glossary.md`](github_actions_and_packages_glossary.md).

Think of `ci.yml` as:

> **“Before we merge/deploy, prove that the Laravel app and its Docker image work.”**

## 1. Workflow triggers

```yaml
name: Laravel CI
```

Name shown in GitHub Actions.

```yaml
on:
  push:
    branches:
      - main
      - develop

  pull_request:
    branches:
      - main
      - develop

  workflow_call:
```

CI runs when:

* code is **pushed** to `main` or `develop`
* a **PR** targets `main` or `develop`
* another workflow explicitly calls this workflow

The last one is important for `docker-publish.yml`:

```text
docker-publish.yml
       ↓
    calls CI
       ↓
   run tests
       ↓
 if tests pass → build/publish image
```

So you don't duplicate the test logic.

---

## 2. `test` job

```yaml
jobs:
  test:
```

This job checks that **Laravel itself works**.

### Runner

```yaml
runs-on: ubuntu-latest
```

GitHub gives you a temporary Ubuntu machine.

### PHP version

```yaml
strategy:
  matrix:
    php-version: [8.4]
```

Currently this means:

> Run tests with PHP 8.4.

A matrix becomes useful if later you want:

```yaml
php-version: [8.3, 8.4]
```

Then GitHub runs the tests twice.

---

## 3. PostgreSQL service

```yaml
services:
  postgres:
    image: postgres:16-alpine
```

GitHub starts a **temporary PostgreSQL 16 container** for the tests.

```yaml
POSTGRES_DB: testing
POSTGRES_USER: postgres
POSTGRES_PASSWORD: secret
```

Creates the test database/user.

```yaml
ports:
  - 5432:5432
```

Makes PostgreSQL available to the CI machine at:

```text
127.0.0.1:5432
```

### Health check

```yaml
options: >-
  --health-cmd pg_isready
  ...
```

GitHub checks:

> "Is PostgreSQL actually ready to accept connections?"

before your tests need it.

---

## 4. Checkout code

```yaml
- name: Checkout code
  uses: actions/checkout@v4
```

Downloads your repository into the GitHub runner.

Without this, the runner wouldn't have your Laravel code.

---

## 5. Setup PHP

```yaml
- name: Setup PHP
  uses: shivammathur/setup-php@v2
```

Installs/configures PHP.

```yaml
php-version: ${{ matrix.php-version }}
```

Uses PHP 8.4 from the matrix.

```yaml
extensions: mbstring, bcmath, ..., pdo_pgsql, ...
```

Installs the PHP extensions Laravel needs.

For example:

```text
pdo_pgsql → allows PHP/Laravel to talk to PostgreSQL
gd        → image processing
intl      → internationalization
mbstring  → multibyte strings
```

```yaml
coverage: none
```

Don't generate code-coverage information → faster CI.

---

## 6. Composer cache

```yaml
- name: Get Composer cache directory
```

Finds where Composer stores downloaded packages.

Then:

```yaml
actions/cache@v4
```

stores that cache between CI runs.

So instead of downloading everything again:

```text
First CI:
Composer → download packages

Next CI:
Composer → reuse cache → faster
```

This:

```yaml
hashFiles('composer.lock')
```

makes the cache depend on `composer.lock`.

If dependencies change → new cache.

---

## 7. Install dependencies

```yaml
composer install --prefer-dist --no-progress --no-interaction
```

Installs Laravel's PHP dependencies.

Basically:

```text
composer.lock
      ↓
composer install
      ↓
vendor/
```

---

## 8. Prepare Laravel

```yaml
cp .env.example .env
php artisan key:generate
```

Creates the Laravel environment file and generates:

```text
APP_KEY
```

Laravel needs this for encryption, cookies, etc.

---

## 9. Run tests

```yaml
- name: Run tests
  env:
    DB_CONNECTION: pgsql
    DB_HOST: 127.0.0.1
    DB_PORT: 5432
    DB_DATABASE: testing
    DB_USERNAME: postgres
    DB_PASSWORD: secret
  run: php artisan test
```

This is the important part.

It tells Laravel:

> "For this CI run, use PostgreSQL."

Then:

```bash
php artisan test
```

runs PHPUnit/Pest tests.

The flow is:

```text
Laravel code
    ↓
install dependencies
    ↓
start PostgreSQL
    ↓
configure Laravel → PostgreSQL
    ↓
php artisan test
```

If tests fail → **CI fails**.

---

## 10. `docker-build` job

```yaml
docker-build:
```

This is a **separate job**.

It asks:

> "Can I actually build the Docker image?"

This catches Dockerfile problems early.

### Checkout

Same as before:

```yaml
actions/checkout@v4
```

Get source code.

### Buildx

```yaml
docker/setup-buildx-action@v3
```

Sets up modern Docker Buildx for building images and caching.

---

## 11. Build the image

```yaml
- name: Build image
  uses: docker/build-push-action@v6
```

Builds your Dockerfile.

```yaml
context: .
```

Use the current repository as Docker build context.

```yaml
push: false
```

**Do not push to Docker Hub/GHCR.**

This is CI, not publishing.

```yaml
load: true
```

Load the resulting image into Docker so later steps can run it.

```yaml
tags: laravel-shop-api:ci
```

Give it this local name:

```text
laravel-shop-api:ci
```

---

## 12. Docker build cache

```yaml
cache-from: type=gha
cache-to: type=gha,mode=max
```

Use GitHub Actions' cache for Docker layers.

Similar idea to Composer cache:

```text
previous Docker build
        ↓
cached layers
        ↓
next build is faster
```

---

## 13. Verify PHP extensions

```yaml
docker run --rm --entrypoint php laravel-shop-api:ci -m
```

Runs PHP **inside the newly built Docker image** and asks:

```text
php -m
```

which means:

> Show installed PHP modules/extensions.

Then the loop checks that every required extension exists.

For example:

```text
gd         ✓
pdo_pgsql  ✓
pgsql      ✓
bcmath     ✓
...
```

If one is missing:

```text
Missing PHP extension: xyz
```

and CI fails.

This is useful because **your CI PHP environment and your Docker PHP environment are different things**.

---

## 14. Verify php-fpm stays alive

```yaml
docker run -d --name fpm-check ...
```

Starts your Laravel container in the background.

Then:

```yaml
sleep 5
```

waits 5 seconds.

Then:

```yaml
docker inspect ...
```

asks:

> Is the container still running?

If the container crashed immediately:

```text
status = exited
```

→ CI fails.

If:

```text
status = running
```

→ good.

### Why this matters

Your Dockerfile is expected to run PHP-FPM in the **foreground**.

If PHP-FPM accidentally starts and exits, your container would immediately die in production.

This test catches that.

---

## Big picture

Your entire `ci.yml` is essentially:

```text
                 GitHub Actions
                       │
          ┌────────────┴────────────┐
          │                         │
       TEST JOB                DOCKER JOB
          │                         │
   Setup PHP 8.4              Build Docker image
          │                         │
   Start PostgreSQL            Check PHP extensions
          │                         │
   composer install            Check php-fpm
          │                         │
   php artisan test
          │
      ✓ Laravel works
```

## Most important distinction

### `test` job

> **Does my Laravel application work?**

### `docker-build` job

> **Does my production Docker image build correctly and contain what it needs?**

And importantly, **neither job publishes the image**.

Publishing is handled separately by your `docker-publish.yml`.
