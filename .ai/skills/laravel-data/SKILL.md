---
name: laravel-data
description: 'Use when creating, editing, or reviewing spatie/laravel-data Data objects in this app — anything under app/Data/**, any class extending Spatie\LaravelData\Data, Data as an Action input/output type, a Data object bound as a public Livewire property, a Data class cast on an Eloquent JSON column, an API endpoint returning a Data object, or questions about config/data.php. Covers: make:data, domain namespacing, validation (rules(), validation attributes, inference, ::validateAndCreate), Eloquent casting, Optional/partial updates, DataCollection, Livewire synth binding (enabled in this app), API output, and when a Data object is the wrong tool. Trigger on "DTO", "data transfer object", "typed input", "Data object", spatie/laravel-data, Optional::class, or DataCollectionOf.'
license: MIT
metadata:
  author: project
---

# spatie/laravel-data (this app)

Package: `spatie/laravel-data` v4.23. Config: `config/data.php`.

**Read `.ai/rules/data.md` first** — it is the decision record (domain namespacing, why Data is this app's API
layer instead of Eloquent API Resources, why synths are on, why validation strategy is left at default). This
skill is the how-to; that rule file is the why. Don't contradict it.

`search-docs` does **not** index this package — its results return `laravel/framework` and Livewire hits
instead. For anything beyond these files, read `vendor/spatie/laravel-data/src` directly (attributes live in
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

```bash
vendor/bin/sail artisan make:data --namespace=Automation RuleConditionData
```

Lands at `app/Data/Automation/RuleConditionData.php` as `App\Data\Automation\RuleConditionData`. Match the
namespace to the domain folder the caller lives in (`Automation`, `Extraction`, `Matters`, `Tasks`, ...),
mirroring `app/Actions/<Domain>` / `app/Support/<Domain>`. `config/data.php` sets the `Data` suffix — keep it
(`RuleConditionData`, not `RuleCondition`).

```php
use Spatie\LaravelData\Data;

class RuleConditionData extends Data
{
    public function __construct(
        public readonly string $field,
        public readonly string $operator,
        public readonly string|int|null $value,
    ) {}
}
```

Constructor-promoted, `readonly` by default. Drop `readonly` only on properties a Livewire component mutates
(see `references/livewire.md`).

## Construction

`Data::from($anything)` accepts an array, model, Eloquent collection, JSON string, another Data object, or a
`Request`. Validation fires **only** when the source is request-like (`validation_strategy` is `OnlyRequests`).

```php
// Input boundary — validates
$data = RuleConditionData::from($request);
$data = RuleConditionData::validateAndCreate($arrayFromWebhook);

// Internal reconstruction — no re-validation, by design
$data = RuleConditionData::from($rule->conditions_payload);
```

Other entry points: `::validate($payload)` (validate without constructing), `::optional($payload)` (returns
`null` instead of throwing on `null` input), `::collect($items)` (arrays/collections/paginators of Data).

## Casts already wired

Global `DateTimeInterface` and `BackedEnum` casts/transformers are on in `config/data.php`, so Carbon- and
enum-typed properties just work. `AppServiceProvider` binds `Date::use(CarbonImmutable::class)` — type date
properties `CarbonImmutable` (or `CarbonInterface`), matching the Action convention in `.ai/rules/actions.md`.
For a one-off shape use `#[WithCast(...)]` on the property; don't add a global cast.

Note `features.cast_and_transform_iterables` is `false` and `EnumerableCast` is commented out — a plain
`array`/`Collection` property is passed through untouched. Use `#[DataCollectionOf(...)]` when the elements are
themselves Data.

## `Optional` vs `null`

When a property may be *absent* from the input (PATCH-style update, optional automation-rule field), type it
`Optional|string` and default to `new Optional`. `null` means "explicitly cleared"; `Optional` means "not
provided". Don't rebuild this distinction by hand with `array_key_exists`.

```php
use Spatie\LaravelData\Optional;

public function __construct(
    public readonly string $name,
    public readonly Optional|string|null $description = new Optional,
) {}
```

`Optional` properties are omitted from `toArray()` output entirely, which is what makes them safe for partial
updates: `$model->update(array_filter($data->toArray(), ...))` is unnecessary — the key simply isn't there.

## Serialized payloads

Data objects implement `Arrayable`/`JsonSerializable` correctly (nested Data, Carbon, enums included), so they
serialize cleanly as a queued job constructor property, a cache value, or a webhook body — no manual
array-shaping on either end. `BaseData::__sleep()` handles the queue round-trip. A plain array forces the read
side to re-derive the shape; a Data class documents it once.

## Structure caching (production only)

`structure_caching.enabled` is `true`, `directories` is `[app_path('Data')]`, store follows
`config('cache.default')`. Warm in deploy with `vendor/bin/sail artisan data:cache-structures` if reflection
cost shows up in profiling. Not needed in local dev. **Data classes outside `app/Data/` are not cached** — one
more reason to keep them all under the domain namespaces above.

## When *not* to use a Data object

- A single Eloquent model passed between internal methods — pass the model. (Building a Data object *from* a
  model for API output is a different case, and is the right call — see `references/transforming.md`.)
- A one-off return value read at exactly one call site — a plain array or PHPDoc array-shape is fine.

## Polymorphic / abstract Data

`PropertyMorphableData`, `#[PropertyForMorph]`, `DataMorphClassResolver` — the package supports one abstract Data
class resolving to subtypes by a discriminator field. First used for `App\Data\Integrations\AuthCredentialsData`
(OAuth vs IMAP credentials share no fields). See `.ai/rules/data.md` for the convention: give every morph property
a default, and keep discriminator property types identical across subclasses (PHP property covariance). Read
`vendor/spatie/laravel-data/src/Contracts/PropertyMorphableData.php` before adding another one — this fits a real
either/or shape, not optional/partial variation (use `Optional` properties for that instead).
