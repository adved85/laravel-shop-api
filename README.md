# laravel-shop-api

Backend REST API for **abc-shop** — an e-commerce platform built with Laravel 13, PostgreSQL, and Laravel Sanctum.

---

## Stack

- **Laravel 13** · **PHP 8.4** · **PostgreSQL**
- **Laravel Sanctum** — token-based API authentication
- **Devilbox** — local Docker development environment

---

## Two Core Systems

### 1. Unified API Response & Error Handling

Every response — success, validation failure, exception, or 404 — returns the same JSON shape, from every layer of the application.

```json
{ "success": true,  "message": "Created", "code": 201, "data": {} }
{ "success": false, "message": "Validation failed", "code": 422, "errors": {} }
```

Controllers use a dedicated `ApiResponse` factory for consistent responses:

```php
$this->apiResponse->ok($data)               // 200
$this->apiResponse->created($resource)      // 201
$this->apiResponse->paginated($page, ResourceClass::class)  // 200 + pagination
$this->apiResponse->noContent()             // 204
$this->apiResponse->notFound('message')     // 404
$this->apiResponse->unauthenticated()       // 401
$this->apiResponse->validationError($msg, $errors)  // 422
```

Built on three layers that work in order:

| Layer | File | Handles |
|-------|------|---------|
| FormRequest | `app/Http/Requests/ApiFormRequests.php` | Validation & authorization failures |
| Response factory | `app/Support/API/ApiResponse.php` | All controller responses |
| Global handler | `bootstrap/app.php` | Uncaught exceptions (404, 401, 500, …) |

→ [Full documentation](app/docs/guide/unified_api_response_system.md)

---

### 2. API Versioning Structure

Routes, controllers, resources, and requests are versioned and fully isolated.
Adding v2 never touches v1. Models and migrations are shared.

```
routes/api/v1.php          →  /api/v1/...
routes/api/v2.php          →  /api/v2/...
routes/admin/v1.php        →  /api/admin/v1/...

app/Http/Controllers/Api/V1/CategoryController.php
app/Http/Controllers/Admin/V1/CategoryController.php
app/Http/Resources/Admin/V1/CategoryResource.php
```

→ [Full documentation](app/docs/guide/api_versioning_structure.md)

---

## Authentication

Admin endpoints are protected with Sanctum token authentication.

| Endpoint | Method | Auth |
|----------|--------|------|
| `/api/admin/login` | `POST` | — |
| `/api/admin/register` | `POST` | — |
| `/api/admin/logout` | `POST` | Bearer token |
| `/api/admin/v1/*` | any | Bearer token |

---

## Local Development

```bash
# start Devilbox
docker-compose up -f
./shell.sh

# install dependencies
composer install

# migrate & seed
php artisan migrate
php artisan db:seed

# verify routes
php artisan route:list
```

Local URL: `http://laravel-shop-api.dvl.to:88`

> Port 88 is used instead of 80 — configured in `devilbox/.env`:
> `HOST_PORT_HTTPD=88`

---

## CI, Docker Image & Release Pipeline

Every push and PR runs the test suite (against a real PostgreSQL service
container, not SQLite) plus a Dockerfile build-and-sanity-check, via GitHub
Actions. Cutting a version tag builds a production image — multi-stage,
`php-fpm-alpine`, no nginx baked in (fronted by a separate service) — and
publishes it to GHCR, consumed by the `shop-infrastructure` compose setup:

```yaml
api:
  image: ghcr.io/adved85/laravel-shop-api:${API_VERSION}
```

**Cutting a release:**

```bash
git push origin <branch>   # push code first — a tag must point at a pushed commit
git tag v1.2.0
git push origin v1.2.0     # triggers docker-publish.yml: re-run tests → build → push to GHCR
```

Then, **separately and manually**, in the `shop-infrastructure` repo: bump
`API_VERSION` and `docker compose pull && docker compose up -d`. Publishing
an image and deploying it are two different steps — this repo only handles
the first; there's no production server yet for the second.

→ [Full documentation](app/docs/guide/docker_image_and_release_pipeline.md)
· line-by-line: [ci.yml](app/docs/guide/docker_image_and_pipelines/ci.md),
[docker-publish.yml](app/docs/guide/docker_image_and_pipelines/docker_publish.md),
[terms glossary](app/docs/guide/docker_image_and_pipelines/github_actions_and_packages_glossary.md)

---

## How This Was Built

The app was built up in layers, each mirrored by a git branch/PR and, where
it exists, a write-up in [`app/docs/guide/`](app/docs/guide/). Read the docs
in this order:

| # | Layer | Docs | Branch |
|---|-------|------|--------|
| 1 | Sanctum auth + the unified API response system — every response, success or failure, in one JSON shape | [unified_api_response_system.md](app/docs/guide/unified_api_response_system.md) | [L1](https://github.com/adved85/laravel-shop-api/tree/L1) |
| 2 | Versioned API structure — isolated `v1`/`v2` routes, controllers, resources — plus the *Category* resource and its feature tests | [api_versioning_structure.md](app/docs/guide/api_versioning_structure.md) | [L2](https://github.com/adved85/laravel-shop-api/tree/L2) |
| 3 | Admin V1 Brand endpoints, and a `Concern` shared by Brand + Category that computes their sort `order` explicitly in code instead of a static DB default — plus the `refresh()`-after-`create()` bug that exposed | [admin_v1_brand_resource.md](app/docs/guide/admin_v1_brand_resource.md) + [explicit_incremental_ordering.md](app/docs/guide/explicit_incremental_ordering.md) | [L3](https://github.com/adved85/laravel-shop-api/tree/L3) |
| 4 | 🧩 Docker image, GitHub CI & the GHCR release pipeline — multi-stage build, tests against real Postgres, tag-triggered publishing | [docker_image_and_release_pipeline.md](app/docs/guide/docker_image_and_release_pipeline.md) + [line-by-line](app/docs/guide/docker_image_and_pipelines/) | [L4](https://github.com/adved85/laravel-shop-api/tree/L4) |

Each doc explains the *why* behind that layer — decisions made, bugs hit —
not just the *what*.

---

## Project Structure

🧩 marks a directory or file that was **deliberately, strategically added**
for this project's architecture — as opposed to Laravel's own default
skeleton, left unmarked:

```text
laravel-shop-api/
├── app/
│   ├── Http/
│   │   ├── Controllers/Admin/V1/     🧩 versioned controllers — v2 slots in beside V1, untouched
│   │   ├── Requests/
│   │   │   ├── ApiFormRequests.php   🧩 shared base — turns validation failures into the standard envelope
│   │   │   └── Admin/V1/             🧩 versioned form requests
│   │   └── Resources/Admin/V1/       🧩 versioned API resources — control exactly what JSON goes out
│   ├── Models/
│   │   └── Concerns/
│   │       └── HasComputedOrder.php  🧩 computes `order` in code, shared by Brand & Category
│   ├── Support/API/
│   │   └── ApiResponse.php           🧩 the one place every response shape gets decided
│   └── docs/guide/                   🧩 every architectural decision, written down as it was made
│
├── routes/
│   ├── api.php
│   └── admin/v1.php                  🧩 versioned route file, isolated from api/v1
│
├── database/
│   ├── factories/
│   ├── migrations/
│   └── seeders/
│
├── tests/
│   └── Feature/                      🧩 one file per resource, mirrors the controller structure
│
├── Dockerfile                        🧩 multi-stage: base → deps → build → runtime
├── docker/                           🧩 php-fpm pool config, opcache tuning, entrypoint script
├── .github/
│   └── workflows/                    🧩 ci.yml (tests + image check), docker-publish.yml (→ GHCR)
│
├── config/
├── public/
├── resources/
├── storage/
└── bootstrap/
```
