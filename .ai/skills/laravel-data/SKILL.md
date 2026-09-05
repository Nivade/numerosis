---
name: laravel-data
description: 'Use when creating, editing, or reviewing spatie/laravel-data Data objects in this package — anything under src/Data/**, any class extending Spatie\LaravelData\Data, Data as an Action input/output type crossing Fortify''s array $input boundary, a Data class cast on an Eloquent JSON column, or an API endpoint returning a Data object. Covers: make:data (moved into src/ by hand, Testbench-style), domain namespacing, validation (rules(), validation attributes, inference, ::validateAndCreate), Eloquent casting, Optional/partial updates, DataCollection, API output, and when a Data object is the wrong tool. Trigger on "DTO", "data transfer object", "typed input", "Data object", spatie/laravel-data, Optional::class, or DataCollectionOf.'
license: MIT
metadata:
  author: project
---

# spatie/laravel-data (this package)

Package: `spatie/laravel-data` `^4.18` (installed 4.23). **No published
config** — `config/data.php` does not exist here and there is no
`.ai/rules/data.md`; this repo has no per-domain data-decision record, only
this skill. `vendor/spatie/laravel-data/config/data.php` is the live default
for everything below; read it directly rather than assuming a value.

`search-docs` does **not** index this package — its results return
`laravel/framework` and Livewire hits instead. For anything beyond these
files, read `vendor/spatie/laravel-data/src` directly (attributes live in
`src/Attributes/`, per-feature traits in `src/Concerns/`).

## Where to read next

| Task | File |
| --- | --- |
| Rules, validation attributes, inference, messages, nested/partial validation | `references/validation.md` |
| Data as an Eloquent cast on a JSON column, building from models, collections | `references/eloquent.md` |
| Public Livewire properties, `wire:model`, forms, validation in components | `references/livewire.md` |
| API output, `Lazy`, include/exclude, wrapping, `toArray` shaping | `references/transforming.md` |

Read only the file(s) the task needs.

## Creating a Data class

This is a package, not an application — `make:data` generates into
`workbench/app/Data/`, Testbench's scratch app, never into `src/`. Per
`CLAUDE.md`/Boost's own "Do Things the Laravel Way" rule, run it anyway and
move the result:

```bash
php artisan make:data --namespace=Auth RegistrationData
```

then move `workbench/app/Data/Auth/RegistrationData.php` to
`src/Data/Auth/RegistrationData.php` and rewrite its namespace to
`Nvade\Numerosis\Data\Auth`. Match the namespace to the domain the caller
lives in — `src/Data/{Auth,Billing,Tenancy}` are the domains in use today,
mirroring `src/Actions/<Domain>` / `src/Services/<Domain>`.

```php
<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Data\Auth;

use Spatie\LaravelData\Data;

class RuleConditionData extends Data
{
    public function __construct(
        public string $field,
        public string $operator,
        public string|int|null $value,
    ) {}
}
```

Constructor-promoted, **not `readonly`** — no Data class in `src/Data/`
declares a `readonly` property today (checked, not assumed); follow that
convention rather than a generic starter-kit default. `declare(strict_types=1)`
is required package-wide, enforced by `pint.json`.

## Crossing Fortify's `array $input` boundary

This package's own live pattern, not generic: every Fortify action contract
(`CreatesNewUsers::create(array $input)`, `UpdatesUserPasswords::update($user, array $input)`,
…) is typed `array`, and this repo converts on entry with a Data class rather
than a hand-rolled `Validator::make()`. See `src/Data/Auth/RegistrationData.php`
(backs `Actions\Auth\CreateRegisteredUser`) and
`src/Data/Auth/UpdateProfileData.php` (backs `Actions\Auth\UpdateUserProfile`)
for the shape: `public static function rules(): array` on the Data class
itself, called via `Data::validateAndCreate($input)` inside the action's
`handle()`/`create()`. This is a deliberate deviation from a Form-Request-only
convention — see `docs/extending.md`'s Fortify section for why (the same
action runs from Fortify's controller *and* a Livewire settings component;
only one of those has a Form Request).

`#[SensitiveParameter]` (native PHP, not a package attribute) goes on any
plain-string secret crossing this boundary — `RegistrationData::$password`
is the example. Sentry is wired in this package
(`src/Concerns/TagsSentryScopeWithTenant.php`), so an unmarked secret leaves
the machine in a stack trace.

## Construction

`Data::from($anything)` accepts an array, model, Eloquent collection, JSON
string, another Data object, or a `Request`. Validation fires **only** when
the source is request-like (`validation_strategy` defaults to
`OnlyRequests` — confirm in `vendor/spatie/laravel-data/config/data.php`
since nothing here overrides it).

```php
// Input boundary — validates
$data = RegistrationData::validateAndCreate($input);

// Internal reconstruction — no re-validation, by design
$data = TenantProvisionData::from($provision->payload);
```

Other entry points: `::validate($payload)` (validate without constructing),
`::optional($payload)` (returns `null` instead of throwing on `null` input),
`::collect($items)` (arrays/collections/paginators of Data).

## Casts already wired

Global `DateTimeInterface` and `Illuminate\Contracts\Support\Arrayable`
transformers are on by package default (see the vendor config's
`transformers` array — nothing here overrides it). For a one-off shape use
`#[WithCast(...)]` on the property; don't add a global cast without a reason
that applies package-wide.

`features.cast_and_transform_iterables` is `false` by default (unpublished,
so this is the package's own stock value) — a plain `array`/`Collection`
property is passed through untouched. Use `#[DataCollectionOf(...)]` when the
elements are themselves Data.

## `Optional` vs `null`

When a property may be *absent* from the input (a PATCH-style update, an
optional field on a settings form), type it `string|Optional` and default to
`new Optional`. `null` means "explicitly cleared"; `Optional` means "not
provided". `src/Data/Auth/UpdateProfileData.php` is the live example —
`UpdateUserProfile` does `$user->fill($data)` then checks `isDirty('email')`,
so a bare `null` arriving from a field the form simply didn't submit would
clear the address rather than leave it alone. Don't rebuild this distinction
by hand with `array_key_exists`.

```php
use Spatie\LaravelData\Optional;

public function __construct(
    public string $name,
    public string|Optional $description = new Optional,
) {}
```

`Optional` properties are omitted from `toArray()` output entirely, which is
what makes them safe for partial updates: `$model->update(array_filter($data->toArray(), ...))`
is unnecessary — the key simply isn't there.

## Serialized payloads

Data objects implement `Arrayable`/`JsonSerializable` correctly (nested Data,
Carbon, enums included), so they serialize cleanly as a queued job
constructor property (`Actions\Tenancy\ProvisionTenant`'s `TenantProvisionData`
is the live example), a cache value, or a webhook body — no manual
array-shaping on either end. `BaseData::__sleep()` handles the queue
round-trip. A plain array forces the read side to re-derive the shape; a Data
class documents it once.

## Structure caching (production only)

The package default is `structure_caching.enabled === true`, `directories`
`[app_path('Data')]`. **`app_path('Data')` is an application-shaped default
that does not point at this package's own `src/Data/`** — this repo has no
`app/` directory at all (`CLAUDE.md`: "no Sail and no application"). A host
consuming this package would need to publish `data.php` and add `src/Data`
under its own vendor path, or this directory's classes are simply not
covered by structure caching. Don't assume caching is warm for `src/Data/`
without checking a specific host's published config.

## When *not* to use a Data object

- A single Eloquent model passed between internal methods — pass the model.
  (Building a Data object *from* a model for API output is a different case,
  and is the right call — see `references/transforming.md`.)
- A one-off return value read at exactly one call site — a plain array or
  PHPDoc array-shape is fine.
