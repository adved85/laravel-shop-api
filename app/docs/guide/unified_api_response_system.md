# Unified API Response System

Every response from every route — whether it comes from a controller,
a failed validation, a thrown exception, or a 404 — returns the same
JSON shape. No surprises for the frontend.

---

## Response Shapes

**Success**
```json
{
    "success": true,
    "message": "...",
    "code":    200,
    "data":    {}
}
```

**Error — no field detail**
```json
{
    "success": false,
    "message": "...",
    "code":    400
}
```

**Error — with field detail (e.g. validation)**
```json
{
    "success": false,
    "message": "Validation failed",
    "code":    422,
    "errors":  { "email": ["..."], "password": ["..."] }
}
```

> **Rule:** `"errors"` only appears when there are field-level details.
> `"data"` only appears on success responses.

---

## The Four Pieces

### Piece 1 — `app/Enums/HTTPCodes.php`

Single source of truth for every status code used in the project.
No magic numbers anywhere else.

```php
case OK                    = 200
case CREATED               = 201
case ACCEPTED              = 202   // async / queued operation started
case NO_CONTENT            = 204   // success, nothing to return (e.g. logout)
case BAD_REQUEST           = 400
case UNAUTHENTICATED       = 401   // not logged in / wrong credentials
case UNAUTHORIZED          = 403   // logged in but not allowed
case NOT_FOUND             = 404
case CONFLICT              = 409   // e.g. duplicate resource
case VALIDATION_ERROR      = 422
case TOO_MANY_REQUESTS     = 429
case INTERNAL_SERVER_ERROR = 500
case SERVICE_UNAVAILABLE   = 503
```

Usage anywhere in the project:
```php
HTTPCodes::NOT_FOUND->value   // returns 404 as int
```

---

### Piece 2 — `app/Support/API/ApiResponse.php`

The response factory. Inject it into any controller via the constructor.
It wraps `response()->json()` and enforces the shape above.

#### Two base methods (rarely called directly)

```php
success(mixed $data, string $message, int $status): JsonResponse
    // builds { success: true, message, code, data }

error(string $message, int $status, ?array $errors): JsonResponse
    // builds { success: false, message, code }
    // adds "errors" key only when $errors is not null
```

#### Success helpers — call these in controllers

| Method | Code | Notes |
|--------|------|-------|
| `->ok($data, $message)` | 200 | |
| `->created($data, $message)` | 201 | |
| `->accepted($data, $message)` | 202 | |
| `->noContent()` | 204 | Returns `Response`, not `JsonResponse` |
| `->paginated($paginator, ResourceClass::class)` | 200 | See paginated section in versioning guide |

#### Error helpers — call these in controllers

| Method | Code | Notes |
|--------|------|-------|
| `->badRequest($message)` | 400 | |
| `->unauthenticated($message)` | 401 | Wrong credentials / not logged in |
| `->unauthorized($message)` | 403 | Logged in but forbidden |
| `->notFound($message)` | 404 | |
| `->conflict($message)` | 409 | |
| `->validationError($message, $errors)` | 422 | |
| `->serverError($message)` | 500 | |

#### How to inject it in a controller

```php
class ProductController extends Controller
{
    public function __construct(private ApiResponse $apiResponse) {}

    public function index()
    {
        $products = Product::all();
        return $this->apiResponse->ok($products);
    }

    public function store(ProductRequest $request)
    {
        $product = Product::create($request->validated());
        return $this->apiResponse->created($product);
    }

    public function destroy(Product $product)
    {
        $product->delete();
        return $this->apiResponse->noContent();
    }
}
```

> **Note:** `$data` passed to `ok` / `created` / `accepted` can be an Eloquent model,
> a collection, a plain array, or anything implementing `Arrayable`.

---

### Piece 3 — `app/Http/Requests/ApiFormRequests.php`

Abstract base class for every API `FormRequest`.
Overrides two Laravel hooks so validation and authorization failures
return the standard JSON shape instead of Laravel's default HTML error page.

**When validation fails → `failedValidation()`**
Throws `HttpResponseException` immediately (bypasses the global handler).
```json
{ "success": false, "message": "Validation failed", "code": 422, "errors": { "field": ["..."] } }
```

**When `authorize()` returns false → `failedAuthorization()`**
Throws `HttpResponseException` immediately.
```json
{ "success": false, "message": "Forbidden", "code": 403 }
```

#### How to create an API request

```php
// app/Http/Requests/Admin/ProductRequest.php
namespace App\Http\Requests\Admin;

use App\Http\Requests\ApiFormRequests;

class ProductRequest extends ApiFormRequests
{
    public function authorize(): bool
    {
        return true; // or check roles/policies here
    }

    public function rules(): array
    {
        return [
            'name'  => 'required|string|max:255',
            'price' => 'required|numeric|min:0',
        ];
    }
}
```

```php
// in the controller — validated() gives only the safe, validated fields
public function store(ProductRequest $request)
{
    $validated = $request->validated();
    // ...
}
```

> **Important:** Always extend `ApiFormRequests`, never extend `FormRequest` directly
> for API routes. `FormRequest` alone returns HTML on failure.

---

### Piece 4 — `bootstrap/app.php` (`withExceptions`)

The last safety net. Catches any exception **not** already handled
by a FormRequest (Piece 3) or an explicit controller return (Piece 2).

**This covers:**
- Exceptions thrown by middleware
- Model binding failures (`ModelNotFoundException` → 404)
- Auth middleware failures (`AuthenticationException` → 401)
- Policy/gate failures (`AuthorizationException` → 403)
- Rate limiter (`ThrottleRequestsException` → 429)
- Any uncaught generic exception → 500

**Two guards:**

```php
// tells Laravel to treat api/* as JSON-expecting
// even if the client didn't send Accept: application/json
$exceptions->shouldRenderJsonWhen(fn($req) => $req->is('api/*'));

// inside the render callback: non-API routes fall through
// to Laravel's default HTML error page (Ignition / Blade)
if (!$request->is('api/*')) return null;
```

**Environment behaviour:**

| Environment | 500 response |
|-------------|-------------|
| `local` | Includes `"trace"` (first 5 frames) for debugging |
| `production` | Logs via `report($e)`, returns generic message |

---

## How the Three Layers Work Together

```
Request arrives at POST /api/admin/products
    │
    ├─ ProductRequest::authorize() returns false
    │       → ApiFormRequests::failedAuthorization()
    │       → HttpResponseException thrown  ← response sent here
    │       → { success: false, message: "Forbidden", code: 403 }
    │
    ├─ ProductRequest::rules() fails
    │       → ApiFormRequests::failedValidation()
    │       → HttpResponseException thrown  ← response sent here
    │       → { success: false, message: "Validation failed", code: 422, errors: { ... } }
    │
    ├─ Controller runs, calls $this->apiResponse->created($product)
    │       → ApiResponse::success()  ← response sent here
    │       → { success: true, message: "Created", code: 201, data: { ... } }
    │
    └─ Controller throws unexpected exception (e.g. DB is down)
            → bootstrap/app.php render() catches it  ← response sent here
            → { success: false, message: "Internal server error", code: 500 }
```

The three layers are ordered by specificity:

**FormRequest failures → ApiResponse in controller → Global handler**

---

## Quick Reference

| What happened | Method to use |
|---------------|---------------|
| Resource returned | `->ok($data)` |
| Resource created | `->created($data)` |
| Async job accepted | `->accepted($data)` |
| Delete / logout success | `->noContent()` |
| Bad input (not validation) | `->badRequest('reason')` |
| Wrong credentials | `->unauthenticated('message')` |
| No permission | `->unauthorized('message')` |
| Resource not found | `->notFound('message')` |
| Duplicate resource | `->conflict('message')` |
| Validation failed | `->validationError('msg', $errors)` |
| Unrecoverable error | `->serverError('message')` |

---

## Adding a New Resource — Checklist

- [ ] Create `app/Http/Requests/Admin/FooRequest.php` — extends `ApiFormRequests`, define `authorize()` and `rules()`
- [ ] Create `app/Http/Controllers/Admin/FooController.php` — inject `ApiResponse` via constructor
- [ ] Use `$request->validated()` — never `$request->all()`
- [ ] Return `$this->apiResponse->ok()` / `->created()` / `->noContent()` / etc.
- [ ] Register routes in `routes/api.php`
- [ ] Test that validation failure returns 422 with `"errors"` field
- [ ] Test that a missing route returns 404 with the standard shape
- [ ] Test that unauthenticated access returns 401 (if the route is protected)
