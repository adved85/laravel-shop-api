# laravel-shop-api

Backend REST API for **abc-shop** — an e-commerce platform built with Laravel 13, PostgreSQL, and Laravel Sanctum.

---

## Stack

- **Laravel 13** · **PHP 8.3** · **PostgreSQL**
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
