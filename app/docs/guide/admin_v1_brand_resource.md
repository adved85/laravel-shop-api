# Admin V1 Brand Resource

Adds the Brand resource under Admin V1, following the exact same pattern as
Category (see [api_versioning_structure.md](api_versioning_structure.md)).

> **The only structural difference:** Brand does **not** have a `parent_id`.
> Everything else — `name`, `slug`, `in_use`, `order`, timestamps — matches
> the `categories` table.

---

## 1. Model + Migration + Factory + Seeder

```bash
php artisan make:model Brand -mfs
```

**Migration** — same columns as `categories`, minus `parent_id`:

```php
$table->id();
$table->string('name')->unique();
$table->string('slug')->unique();
$table->boolean('in_use')->default(true);
$table->unsignedInteger('order')->default(0)->index();
$table->timestamps();
```

**Model** — fillable array + casts:

```php
protected $fillable = ['name', 'slug', 'order', 'in_use'];

protected function casts(): array
{
    return [
        'in_use' => 'boolean',
        'order'  => 'integer',
    ];
}
```

**Factory** — mirror `CategoryFactory`'s `definition()`:

```php
$name = $this->faker->unique()->words(fake()->numberBetween(1, 3), true);

return [
    'name'   => ucwords($name),
    'slug'   => Str::slug($name),
    'in_use' => true,
    'order'  => $this->faker->numberBetween(0, 100),
];
```

> **Watch out:** `words()` needs the second argument `true` to return a
> string — without it, Faker returns an array and `Str::slug()` breaks
> immediately. `unique()` avoids collisions with the unique `name`/`slug`
> columns.

**Seeder** — same shape as `CategorySeeder`:

```php
Brand::factory()->count(7)->create();
```

```bash
php artisan migrate
```

---

## 2. Controller, Resource, Request

```bash
php artisan make:controller --api --model=Brand Admin/V1/BrandController
php artisan make:resource Admin/V1/BrandResource
php artisan make:request Admin/V1/BrandRequest
```

**BrandResource** — return every field explicitly: `id`, `name`, `slug`,
`in_use`, `order`, `created_at`, `updated_at`. No `parent_id`, unlike
`CategoryResource`.

**BrandRequest**

- Extend `ApiFormRequests` (not `FormRequest`)
- `rules()` branches on `isMethod('POST' | 'PUT' | 'PATCH')`:

  | Method | Behaviour |
  |--------|-----------|
  | `POST` / `PUT` | `name` and `slug` required |
  | `PATCH` | `name` and `slug` `sometimes` (partial update) |

- `Rule::unique('brands', 'name'/'slug')->ignore($brand->id)` on update
- `prepareForValidation()` — auto-generate `slug` from `name` when omitted
- `attributes()` — readable field labels for validation error messages

**BrandController**

- Inject `ApiResponse` via constructor
- `index()` → `Brand::paginate(20)`, return `apiResponse->paginated(...)`
- `store()` → `Brand::create($request->validated())`, return `->created(new BrandResource(...))`
- `show()` → return `->ok(new BrandResource($brand))`
- `update()` → `$brand->update($request->validated())`, return `->ok(new BrandResource($brand->refresh()))`
- `destroy()` → `$brand->delete()`, return `->noContent()`

---

## 3. Routes

`routes/admin/v1.php`:

```php
use App\Http\Controllers\Admin\V1\BrandController;

Route::apiResource('brands', BrandController::class);
```

```bash
php artisan route:list   # verify
```

---

## 4. Manual Smoke Test

```
POST http://laravel-shop-api.dvl.to:88/api/admin/v1/brands
{
    "name": "Nike"
}
```

→ `201`, slug auto-generated as `nike`, `in_use` defaults `true`, `order` defaults `0`.

---

## 5. Feature Tests

`tests/Feature/AdminV1BrandTest.php` — mirror `AdminV1CategoryTest.php`:
index / store / validation / show / update / destroy / unauthenticated.

```bash
php artisan test --filter=AdminV1BrandTest
```

---

## Sample Brand Names

For manual testing / seeding:

```
Puma
Flying Machine
Nike
Adidas
Zara
H&M
Levi's
Louis Vuitton
```
