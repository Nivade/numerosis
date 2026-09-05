# Validation

Read `../SKILL.md` first. Package source: `vendor/spatie/laravel-data/src/Attributes/Validation/`,
`src/Concerns/ValidateableData.php`, `src/Resolvers/DataValidationRulesResolver.php`.

This package uses Form Requests normally (`StartCheckoutRequest`, `NumerosisLoginRequest`, …) — a Data class
is **not** the only validation layer here. It becomes the validation layer specifically at Fortify's `array
$input` boundary (`CreatesNewUsers`, `UpdatesUserPasswords`, …), where there is no Form Request to put rules
on; see `../SKILL.md`'s "Crossing Fortify's `array $input` boundary" section.

## When validation actually runs

`config/data.php` is unpublished here — `validation_strategy` is the package's own stock default,
`OnlyRequests` (`vendor/spatie/laravel-data/config/data.php`). That means:

| Call | Validates? |
| --- | --- |
| `Data::from($request)` | yes |
| `Data::from($array)` | **no** |
| `Data::from($model)` / `from(otherData)` | no |
| `Data::validateAndCreate($array)` | yes |
| `Data::validate($array)` | yes, returns the payload, builds nothing |
| `Data::getValidationRules($payload)` | no, returns the resolved rule array |

Use `::from($request)` at HTTP boundaries and `::validateAndCreate()` for any other untrusted array (webhook
body, imported file row, queue message from outside the app). Never assume an internal `::from()` validated
anything.

## Three ways to declare rules

**1. Inferred from the type** — always on, nothing to write; the package's default inferrer stack
(`SometimesRuleInferrer`, `NullableRuleInferrer`, `RequiredRuleInferrer`, `BuiltInTypesRuleInferrer`,
`AttributesRuleInferrer`) applies unconfigured. So:

```php
public string $field;          // required, string
public ?int $threshold;        // nullable, integer
public Optional|string $note;  // sometimes, string
```

Don't restate `required`/`nullable`/`string`/`integer` by hand — the inferrers already added them, and a manual
`rules()` **replaces** the inferred set for that key rather than merging (see `#[MergeValidationRules]` below).

**2. Validation attributes** — the default for anything the type can't express. One attribute class per Laravel
rule in `src/Attributes/Validation/`.

```php
use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Attributes\Validation\In;
use Spatie\LaravelData\Attributes\Validation\Exists;

public function __construct(
    #[Max(255)]
    public readonly string $field,

    #[In(['equals', 'contains', 'greater_than'])]
    public readonly string $operator,

    #[Exists('matters', 'id')]
    public readonly ?int $matterId,
) {}
```

Prefer attributes over `rules()` — they sit next to the property and survive refactors. Note the renames where
a PHP keyword collides: `ArrayType`, `BooleanType`, `Enum`, `GreaterThanOrEqualTo`, `AlphaNumeric`.
`#[CustomValidationAttribute]` wraps an `App\Rules\*` rule object.

**3. `rules()` method** — for anything runtime: a rule depending on another field, a `Rule::` builder, a rule
needing container resolution.

```php
use Spatie\LaravelData\Support\Validation\ValidationContext;

/**
 * @return array<string, mixed>
 */
public static function rules(ValidationContext $context): array
{
    return [
        'value' => $context->payload['operator'] === 'in'
            ? ['required', 'array']
            : ['required', 'string'],
    ];
}
```

The parameter **must be named `$context`** — the package calls it with
`app()->call([$class, 'rules'], ['context' => $validationContext])`, so a renamed parameter fails to resolve.

`ValidationContext` gives `payload` (this object's slice), `fullPayload` (the whole request, useful inside
nested Data), and `path` (the dotted location, for nested error keys). The method is called through the
container, so you may type-hint dependencies.

**`rules()` overwrites inference for the keys it names.** To keep the inferred rules and add to them, put
`#[MergeValidationRules]` on the class.

## Messages and attribute names

Optional static `messages()` and `attributes()` on the Data class, resolved by
`DataValidationMessagesAndAttributesResolver`. Both are called through the container and both merge correctly
across nested Data.

```php
/** @return array<string, string> */
public static function messages(): array
{
    return ['operator.in' => __('validation.automation.operator')];
}
```

User-facing strings go through `__()` per `.ai/rules/lang.md` — don't hardcode English here.

## Nested Data and collections

Nested Data classes validate automatically with dotted keys; you write nothing extra.

```php
class AutomationRuleData extends Data
{
    public function __construct(
        public readonly string $name,
        public readonly TriggerData $trigger,                    // errors at trigger.*
        #[DataCollectionOf(RuleConditionData::class)]
        public readonly array $conditions,                        // errors at conditions.0.field
    ) {}
}
```

`#[DataCollectionOf]` is required on collection properties — without it the element type is unknown and nothing
inside is validated.

## Skipping validation

`#[WithoutValidation]` on a property excludes it from the rule set entirely — for values injected server-side
rather than supplied by the user (see the `#[FromAuthenticatedUser]`, `#[FromRouteParameter]`,
`#[FromContainer]` attributes in `src/Attributes/`, which fill a property without it ever being user input).

Don't reach for `#[WithoutValidation]` to silence a rule you find inconvenient — it means "this value does not
come from the payload".

## Hooking the validator

```php
public static function withValidator(Validator $validator): void
{
    $validator->after(fn (Validator $v) => /* cross-field checks */);
}
```

Use for checks that need the assembled validator. Prefer `rules()` when a plain rule can express it.
