# API Versioning Structure

Every API version is fully isolated — its own routes, controllers, resources,
and requests. Adding v2 never touches v1. Models, migrations, and business
logic are shared and never versioned.

---

## The Versioning Rule

| Versioned | Not versioned |
|-----------|---------------|
| Routes | Models |
| Controllers | Migrations |
| API Resources | Services / business logic |
| Form Requests (when validation changes) | Observers, Events, Jobs |

Models represent the database table — they don't change between versions.
What changes between v1 and v2 is **how** you expose that data, which is the
job of Controllers, Resources, and Requests.

> **Naming convention:**
> `PascalCase` — `V1`, `V2`, `Admin` for PHP class directories
> `lowercase`  — `v1`, `v2`, `admin` for URL path segments

---

## File Structure

```
routes/
    api.php                 ← entry point — registers all versioned route files
    api/
        v1.php              ← public API v1 routes
        v2.php              ← public API v2 routes
    admin/
        v1.php              ← admin-only routes v1

app/Http/
    Controllers/
        Api/
            V1/
                CategoryController.php
            V2/
                CategoryController.php
        Admin/
            V1/
                CategoryController.php
                AuthController.php

    Requests/
        ApiFormRequests.php         ← abstract base (shared, not versioned)
        Admin/
            AuthRequest.php
            V1/
                CategoryRequest.php
        Api/
            V1/
                CategoryRequest.php

    Resources/
        Admin/
            V1/
                CategoryResource.php
        Api/
            V1/
                CategoryResource.php
```

---

## Route Registration — `routes/api.php`

All versioned route files are loaded here. The `api` middleware group
(including rate limiting) is applied automatically to everything in this
file by Laravel's `withRouting(api: ...)`.

```php
// public API — no auth required
Route::prefix('v1')->name('api.v1.')
    ->group(base_path('routes/api/v1.php'));

Route::prefix('v2')->name('api.v2.')
    ->group(base_path('routes/api/v2.php'));

// admin API — auth required
Route::prefix('admin/v1')->name('admin.v1.')->middleware('auth:sanctum')
    ->group(base_path('routes/admin/v1.php'));
```

**Result URLs:**
```
/api/v1/categories
/api/v2/categories
/api/admin/v1/categories
```

### Alternative — drop the `/api` prefix from admin URLs

Register admin routes in the `then` callback in `bootstrap/app.php`.
Useful when the admin panel lives on a completely separate URL root.

```php
->withRouting(
    api: __DIR__.'/../routes/api.php',
    then: function () {
        Route::middleware(['api', 'auth:sanctum'])
            ->prefix('admin/v1')
            ->name('admin.v1.')
            ->group(base_path('routes/admin/v1.php'));
    }
)
```

```
/admin/v1/categories    ← no /api prefix
/api/v1/categories      ← public API still under /api
```

### Inside a versioned route file

Use `apiResource` for standard CRUD — one line registers all five routes:

```php
// routes/admin/v1.php
use App\Http\Controllers\Admin\V1\CategoryController;

Route::apiResource('categories', CategoryController::class);
```

Generated routes:

| Method | URL | Controller method |
|--------|-----|-------------------|
| `GET` | `/api/admin/v1/categories` | `index` |
| `POST` | `/api/admin/v1/categories` | `store` |
| `GET` | `/api/admin/v1/categories/{category}` | `show` |
| `PUT` | `/api/admin/v1/categories/{category}` | `update` |
| `PATCH` | `/api/admin/v1/categories/{category}` | `update` |
| `DELETE` | `/api/admin/v1/categories/{category}` | `destroy` |

```bash
php artisan route:list   # verify — no rebuild needed
```

---

## Artisan Commands — Creating a New Versioned Resource

```bash
# 1. Model + migration + factory + seeder  (once, shared, not versioned)
php artisan make:model Category -mfs

# 2. Controller with route model binding
php artisan make:controller --api --model=Category Admin/V1/CategoryController

# 3. API Resource (version-specific response shape)
php artisan make:resource Admin/V1/CategoryResource

# 4. Form Request (manually placed for the correct versioned namespace)
php artisan make:request Admin/V1/CategoryRequest
```

---

## API Resources — What They Are and Why Use Them

A Resource is a transformer between a Model and the JSON response.
It decides exactly which fields are exposed and how they are formatted.

**Without a Resource** — the controller returns the raw Eloquent model:
every database column is exposed, including internal ones
(`remember_token`, pivot columns, timestamps you didn't want, etc.)

**With a Resource** — the output is explicit and intentional:

```php
// app/Http/Resources/Admin/V1/CategoryResource.php
public function toArray(Request $request): array
{
    return [
        'id'         => $this->id,
        'name'       => $this->name,
        'slug'       => $this->slug,
        'parent_id'  => $this->parent_id,
        'in_use'     => $this->in_use,
        'order'      => $this->order,
        'created_at' => $this->created_at,
        // internal_notes, pivot columns, etc. — not listed, not exposed
    ];
}
```

**Usage in controllers:**

```php
// single item
return $this->apiResponse->ok(new CategoryResource($category));

// after update — refresh first to return the saved state
return $this->apiResponse->ok(new CategoryResource($category->refresh()));

// paginated collection — see paginated() section below
return $this->apiResponse->paginated($categories, CategoryResource::class);
```

Each API version has its own Resource. V2 can add, rename, or remove fields
without touching V1's Resource — this is the main value of versioned Resources.

---

## Paginated Responses — `apiResponse->paginated()`

Laravel's paginated results (`Category::paginate(20)`) carry metadata —
total, current page, links — that must reach the client.
This creates a conflict with the standard response envelope.

### Problem A — wrapping in `ok()` creates nested `data.data`

```php
return $this->apiResponse->ok(CategoryResource::collection($categories));
```
```json
{ "success": true, "code": 200, "data": { "data": [], "links": {}, "meta": {} } }
```
`ApiResponse::success()` wraps under `"data"`, and `ResourceCollection` already
has its own `"data"` key — two `"data"` keys nested. Broken for clients.

### Problem B — returning the collection directly loses the envelope

```php
return CategoryResource::collection($categories);
```
```json
{ "data": [], "links": {}, "meta": {} }
```
No `"success"`, no `"code"`, no `"message"` — inconsistent with every other route.

### Solution — `apiResponse->paginated()`

```php
return $this->apiResponse->paginated($categories, CategoryResource::class);
```
```json
{
  "success": true,
  "message": "Success",
  "code": 200,
  "data": {
    "items": [],
    "pagination": {
      "total": 50,
      "per_page": 20,
      "current_page": 1,
      "last_page": 3,
      "from": 1,
      "to": 20
    },
    "links": {
      "first": "...?page=1",
      "last":  "...?page=3",
      "prev":  null,
      "next":  "...?page=2"
    }
  }
}
```

`"data"` is renamed to `"items"` inside the payload to avoid the key clash.
`"meta"` - to `"pagination"` 

**Method signature:**
```php
paginated(LengthAwarePaginator $paginator, string $resourceClass, string $message = 'Success')
```

All paginator methods used internally are in:
`vendor/laravel/framework/src/Illuminate/Pagination/LengthAwarePaginator.php`

---

## Form Requests — Patterns

All API form requests extend `ApiFormRequests` (not Laravel's `FormRequest` directly).
`ApiFormRequests` overrides `failedValidation()` and `failedAuthorization()` to return
the standard JSON error envelope automatically.

```php
class CategoryRequest extends ApiFormRequests
{
    public function authorize(): bool
    {
        return true;
        // return auth()->user()->isAdmin(); ← role check goes here
    }

    public function rules(): array
    {
        $category = $this->route('category'); // resolved model on PUT/PATCH, null on POST

        $nameRule = Rule::unique('categories', 'name');
        $slugRule = Rule::unique('categories', 'slug');

        if ($category) {
            // ignore the current record so it can be saved with its own name/slug
            $nameRule = $nameRule->ignore($category->id);
            $slugRule = $slugRule->ignore($category->id);
        }

        if ($this->isMethod('POST')) {
            return [
                'name' => ['required', 'string', 'min:2', 'max:200', $nameRule],
                // ...
            ];
        }

        if ($this->isMethod('PUT')) {
            return [
                'name' => ['required', 'string', 'min:2', 'max:200', $nameRule], // full replacement
                // ...
            ];
        }

        if ($this->isMethod('PATCH')) {
            return [
                'name' => ['sometimes', 'string', 'min:2', 'max:200', $nameRule], // partial update
                // ...
            ];
        }

        return [];
    }

    protected function prepareForValidation(): void
    {
        // runs BEFORE rules() — generates slug so it is available for the "required" rule
        if (!$this->has('slug') && $this->has('name')) {
            $this->merge(['slug' => Str::slug($this->name)]);
        }
    }

    public function attributes(): array
    {
        // readable field labels used in validation error messages
        return [
            'name'      => 'category name',
            'parent_id' => 'parent category',
            // ...
        ];
    }
}
```

**Key rules:**

| HTTP method | Behaviour |
|-------------|-----------|
| `POST` | All required fields stay required — creating a new record |
| `PUT` | All required fields stay required — full replacement |
| `PATCH` | All fields become `sometimes` — client sends only what changes |

> Always call `$request->validated()` in the controller, never `$request->all()`.
> Unique rules on update **must** call `->ignore($id)` or the record can never be saved with its own name.

---

## Rate Limiting

**Default:** 60 requests per minute per authenticated user (or per IP if not logged in).

### Where the default is defined — `app/Providers/AppServiceProvider.php`

```php
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\Facades\RateLimiter;

public function boot(): void
{
    RateLimiter::for('api', function (Request $request) {
        return Limit::perMinute(60)->by($request->user()?->id ?: $request->ip());
    });
}
```

The `api` middleware group includes `throttle:api` automatically —
every route in `routes/api.php` is already rate-limited.

### Changing limits

**Global** — edit `Limit::perMinute(60)` in `AppServiceProvider`.

**Per route or group:**

```php
// hard-coded: 10 requests per 1-minute window
Route::middleware('throttle:10,1')->post('/login', ...);

// named limiter — define in AppServiceProvider, apply by name
RateLimiter::for('admin', function (Request $request) {
    return Limit::perMinute(30)->by($request->user()?->id ?: $request->ip());
});
Route::middleware('throttle:admin')->group(base_path('routes/admin/v1.php'));
```

When the limit is exceeded, `ThrottleRequestsException` (HTTP 429) is thrown.
It is already handled in `bootstrap/app.php` and returns the standard error envelope:

```json
{ "success": false, "message": "Too many requests", "code": 429 }
```

---

## Quick Reference — Adding a New Versioned Resource

- [ ] `php artisan make:model Foo -mfs`
- [ ] `php artisan make:controller --api --model=Foo Admin/V1/FooController`
- [ ] `php artisan make:resource Admin/V1/FooResource`
- [ ] `php artisan make:request Admin/V1/FooRequest`
- [ ] Extend `FooRequest` from `ApiFormRequests` (not `FormRequest`)
- [ ] Register route in `routes/admin/v1.php`: `Route::apiResource('foos', FooController::class);`
- [ ] Use `$request->validated()` in all controller methods
- [ ] Wrap single responses: `new FooResource($model)`
- [ ] Wrap paginated responses: `$this->apiResponse->paginated($models, FooResource::class)`
- [ ] `php artisan route:list` to verify
