# Eloquent: casting columns, building from models

Read `../SKILL.md` first. Package source: `vendor/spatie/laravel-data/src/Support/EloquentCasts/`,
`src/Normalizers/ModelNormalizer.php`.

## Casting a JSON column to a Data class

This is the main reason the package is here. A JSON column with a known shape gets cast to a Data class, not to
`'array'`.

```php
use App\Data\Automation\RuleConditionData;

protected function casts(): array
{
    return [
        'condition' => RuleConditionData::class,
    ];
}
```

Reads decode JSON and run `RuleConditionData::from()`; writes call `toJson()`. Assigning either a Data object
or a plain array to the attribute works — `DataEloquentCast::set()` accepts both and normalises.

`null` stays `null` on read unless you pass the `default` argument, which turns a null column into an
empty-payload Data object:

```php
'condition' => RuleConditionData::class.':default',
'secret'    => ApiCredentialsData::class.':encrypted',           // Crypt::encryptString on write
'condition' => RuleConditionData::class.':default,encrypted',    // both
```

`encrypted` uses `Crypt`, so the column must be text/JSON wide enough for ciphertext, and the value is no
longer queryable with `->where('condition->field', ...)`. Only use it for genuinely secret payloads.

### Collections of Data in one column

```php
use Spatie\LaravelData\DataCollection;

protected function casts(): array
{
    return [
        'conditions' => DataCollection::class.':'.RuleConditionData::class,
    ];
}
```

The cast class comes first, the element Data class is the argument. `PaginatedDataCollection` and
`CursorPaginatedDataCollection` also implement `castUsing` but are not meaningful as column casts.

### Migration side

The column is a normal `json` column — nothing package-specific in the migration. Follow
`.ai/rules/migrations.md`. Because it's real JSON, `->where('condition->operator', 'equals')` still works
(unless encrypted), which is the practical advantage over a serialized blob.

### When *not* to cast

- The column is a free-form bag with no stable shape (raw API response kept for debugging) — leave it `'array'`.
- The shape is already normalised into real columns or a related table — don't reintroduce it as JSON.

## Building a Data object from a model

`ModelNormalizer` is enabled in `config/data.php`, so `Data::from($model)` maps attributes by property name.

```php
$data = MatterData::from($matter);
```

Traps:

- **It reads loaded relations only for properties you declare.** A nested `DocumentData $document` property
  pulls `$matter->document` — which lazy-loads and will N+1 across a collection. Eager-load first, or use
  `Lazy::whenLoaded()` (see `transforming.md`).
- **Accessors and appends are visible**; database columns absent from the model's attributes are not. A
  property with no matching attribute throws unless it is `Optional` or has a default.
- **No validation runs** (`OnlyRequests`) — correct, the model is already trusted.
- Property names map to attribute names directly. For snake_case columns feeding camelCase properties, use
  `#[MapInputName(SnakeCaseMapper::class)]` on the property or class; `name_mapping_strategy` is `null`
  globally, so nothing is mapped unless you say so.

Use `#[LoadRelation]` on a nested Data property when you want the package to eager-load that relation while
building, instead of relying on the caller having done it.

## Collections of models

```php
MatterData::collect($matters);                              // array of MatterData
MatterData::collect($matters, DataCollection::class);       // DataCollection wrapper
MatterData::collect($query->paginate());                    // PaginatedDataCollection, meta preserved
```

`collect()` accepts arrays, `Collection`, `LazyCollection`, `Enumerable`, paginators and cursor paginators; the
second argument picks the return type. Prefer the plain array form internally, and the paginator form at API
boundaries so pagination meta survives to the response.
