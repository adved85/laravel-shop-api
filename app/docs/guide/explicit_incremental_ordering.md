# Explicit Incremental Ordering

*branch: L3 · commit: "share order auto-increment via Concern, refresh after create"*

Give Brand and Category a sequential `order` field that actually increments
per new record — computed explicitly in application code via a shared
Concern, not left to a database column default. Along the way, this fixed
a real bug: the API response was returning `"order": null` for a brand-new
record, even though the database had already stored `order: 0`.

This is a common situation, not specific to `order` — any time a field's
value should be computed by the server rather than supplied by the client,
the same problem and the same fix apply.

---

## 1. The problem with a plain DB default

The migration for both tables:

```php
$table->unsignedInteger('order')->default(0)->index();
```

`default(0)` is a **floor, not an auto-increment**. Every new row that
doesn't set `order` explicitly gets the exact same value: `0`. It never
advances on its own. For each new Brand/Category to slot in after the last
one (`0, 1, 2, 3, ...`), something has to compute "the next number" — and a
static column default can't do that. A true auto-incrementing-per-scope
value would need a DB sequence, a trigger, or a window-function default —
mechanisms that are database-engine-specific and invisible to Eloquent.

---

## 2. The bug this caused — before and after

**Before** (`BrandController::store()`, verified from this project's own
git history — commit `6b97ad2`, the original Brand endpoints):

```php
$brand = Brand::create($request->validated());
return $this->apiResponse->created(new BrandResource($brand));
```

The client never sends `order` — it's server-managed, not user input — so
`$request->validated()` never contains an `order` key. Because of that:

- Eloquent's `create()` never assigns `order` on the in-memory `$brand`
  object (it only sets attributes present in the array you hand it)
- the `INSERT` statement sent to Postgres doesn't mention `order` either
- Postgres applies its own column default (`0`) while writing the row

Net effect: the **actual database row** ends up with `order = 0`. But the
`$brand` PHP object handed to `BrandResource` never learned that — its
`order` attribute is still `null` (untouched, and the `'order' =>
'integer'` cast leaves `null` as `null`). The API response says
`"order": null`, while a fresh `SELECT` on that same row would show `0`.

> This is a general Eloquent gotcha, not specific to this project:
> `create()` only populates the in-memory model with the attributes *you*
> gave it. Anything the database itself supplies afterward — a column
> `DEFAULT`, a trigger, a generated column — is invisible to that object
> until you go back and ask the database again.

---

## 3. Fix, part 1 — compute the value explicitly, in PHP

`app/Models/Concerns/HasComputedOrder.php`:

```php
trait HasComputedOrder
{
    public static function computeOrder(array $data): int
    {
        return $data['order'] ?? (static::max('order') ?? -1) + 1;
    }
}
```

- `static::max('order')` asks "what's the highest order value that already
  exists for this model" — a plain SQL `MAX()`, ANSI-standard, behaves
  identically on Postgres (production) and SQLite (tests).
- `?? -1` handles the empty-table case: `max('order')` returns `null` when
  no rows exist yet; `null ?? -1` is `-1`; `-1 + 1` is `0`. The very first
  Brand/Category still correctly starts at `order 0`.
- `$data['order'] ?? (...)` lets a caller override the computed value by
  supplying their own `order` explicitly (e.g. a future reorder endpoint),
  while every normal `create()` falls through to "next available slot."

Used in the controller, **before** `create()`:

```php
$validated = $request->validated();
$validated['order'] = Brand::computeOrder($validated);

$brand = Brand::create($validated);
```

Now `order` is a real, present key in `$validated` — `create()` sets it
correctly on the in-memory model *and* sends it explicitly in the
`INSERT`. No gap between what PHP thinks happened and what the database
stored.

**Why in PHP, not in the database — the deliberate choice.** The
alternative would be a Postgres sequence or an insert trigger computing
the next order server-side. That works, but it's Postgres-specific — this
project's CI runs tests against a real Postgres service (see
[`docker_image_and_release_pipeline.md`](docker_image_and_release_pipeline.md)
for why), but nothing stops a contributor from using SQLite locally, and a
DB-side sequence/trigger written for Postgres simply wouldn't exist on
SQLite. Computing the value in a plain PHP trait means the exact same
logic runs, unchanged, on every database engine this app ever touches.

> **Known limitation, honestly stated.** `computeOrder()` reads the
> current `MAX`, and moments later a separate `INSERT` happens. Under
> concurrent requests creating rows for the same model at nearly the same
> instant, two requests could read the same `MAX` and compute the same
> next order, producing a duplicate. The DB's `default(0)` is now only
> ever a floor value (what a row gets if the trait is bypassed entirely —
> a raw insert, or a seeder that doesn't call `computeOrder`) — it does
> **not** protect against this race. For this admin-only, human-driven
> endpoint, that's an acceptable, deliberate trade-off. A
> high-concurrency write path would need a DB-level unique constraint with
> retry, or a locking read, instead.

---

## 4. Fix, part 2 — `refresh()` before serializing the response

```diff
- $brand = Brand::create($validated);
- return $this->apiResponse->created(new BrandResource($brand));
+ $brand = Brand::create($validated);
+ return $this->apiResponse->created(new BrandResource($brand->refresh()));
```

`refresh()` re-fetches this exact row from the database and overwrites
every attribute on the model with what's actually there. Once part 1
landed, `order` was already correct in-memory before `create()` even
ran — so for *this specific column*, `refresh()` is no longer strictly
load-bearing. It's kept anyway as a general-purpose safety net: the one
line that guarantees a `create()` response always reflects the true
persisted row, regardless of what future columns get added, what defaults
their migrations give them, or whether some other trait/observer mutates
the row after insert. Cheap (one extra `SELECT` on a low-traffic admin
write), and it removes an entire category of "why does the response
disagree with the database" bugs before they can happen.

**The rule of thumb this establishes:** any time a `create()` (or
`update()`) payload doesn't cover every column a model has, don't
serialize whatever `create()`/`update()` handed back — `refresh()` it
first. This project's `update()` methods already followed this rule from
their very first commit (verified: `BrandController::update()` had
`->refresh()` from day one — see
[`admin_v1_brand_resource.md`](admin_v1_brand_resource.md)). This fix
brought `store()` in line with the same rule.

---

## 5. How to reuse this pattern for a new model

- [ ] add `use HasComputedOrder;` to the model
- [ ] add an `unsignedInteger('order')->default(0)->index()` column via
      migration — the default is just the floor for rows that bypass the
      trait, not the increment mechanism itself
- [ ] in `store()`: `$validated['order'] = Model::computeOrder($validated);`
      before calling `create()`
- [ ] return `new ModelResource($model->refresh())`, never
      `new ModelResource($model)`, straight out of `create()`/`update()`

> **Current limitation — read before reusing.** `computeOrder()` and the
> column it reads/writes are hardcoded to the name `order`. A model
> wanting a similarly-computed field under a different name (e.g.
> `priority`) would need to either name its column `order` too, or
> generalize the trait to accept the column name as a parameter —
> `computeOrder()` doesn't do that yet.

---

## Quick reference

- [ ] Set every server-computed field explicitly in `$validated` before
      `create()` — don't lean on a DB column default to do more than
      provide a fallback floor
- [ ] `refresh()` the model before handing it to a Resource, whenever the
      `create()`/`update()` payload might not cover every column
- [ ] Prefer plain-PHP computation (a `MAX()` query, application logic)
      over DB-specific sequences/triggers for anything that must behave
      identically across environments (SQLite in tests, Postgres in prod)
