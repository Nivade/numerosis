# Output: API responses and `toArray` shaping

Read `../SKILL.md` first. There is no `.ai/rules/data.md` in this repo — no decision record exists for using
Data objects as an API layer here; this file is speculative reference, not a recorded convention.

**Status: no API endpoints exist yet.** What follows is the shape the first ones should take. Nothing here is
proven against real routes, so treat specifics as a starting point and verify against
`vendor/spatie/laravel-data/src/Concerns/` (`ResponsableData`, `IncludeableData`, `WrappableData`,
`TransformableData`) when you write the first endpoint. Update this file once conventions settle.

## Returning a Data object

`Data` implements `Responsable`, so a controller returns it directly:

```php
public function show(Matter $matter): MatterData
{
    return MatterData::from($matter);
}

public function index(): PaginatedDataCollection
{
    return MatterData::collect(Matter::query()->paginate(), PaginatedDataCollection::class);
}
```

Paginator meta is preserved by the paginated collection types. The response status comes from
`ResponsableData::calculateResponseStatus()` — `201` for POST, `200` otherwise; anything else needs
`->toResponse($request)` and a manual `response()` wrapper.

## `Lazy` — the reason no second Resource class is needed

`Lazy` properties are excluded from output unless requested, which is how one Data class serves a list endpoint
and a detail endpoint.

```php
use Spatie\LaravelData\Lazy;

public function __construct(
    public readonly string $title,
    public readonly Lazy|DocumentData $document,
) {}

MatterData::from([
    'title' => $matter->title,
    'document' => Lazy::whenLoaded('document', $matter, fn () => DocumentData::from($matter->document)),
]);
```

Variants: `Lazy::create(fn () => ...)` (opt-in), `Lazy::inertia()`, `Lazy::closure()`, plus the attribute forms
`#[AutoLazy]`, `#[AutoWhenLoadedLazy]`, `#[AutoClosureLazy]` which apply the same thing declaratively. Prefer
`whenLoaded` for relations — it makes the N+1 boundary explicit.

Request-side inclusion (`?include=document`) is governed by four static methods on the Data class —
`allowedRequestIncludes()`, `allowedRequestExcludes()`, `allowedRequestOnly()`, `allowedRequestExcept()`. All
four **default to `[]`, meaning the query string cannot change the payload at all.** Returning an array opts
specific paths in; returning `null` allows anything the caller asks for.

Keep the deny-by-default. Only override with an explicit allow-list — `return ['document']` — never `null`: an
unrestricted `include` lets a caller walk arbitrary nested relations and pull data the endpoint never intended
to expose.

## Shaping without Lazy

```php
$data->only('title', 'status');
$data->except('internalNotes');
$data->include('document.pages');
$data->exclude('document');
```

`ignore_invalid_partials` is `false` by package default (unpublished here), so a typo'd partial path throws
instead of silently doing nothing. Keep it that way.

`#[Hidden]` drops a property from output permanently (it stays available internally) — use it for values that
must never reach the wire, rather than remembering an `except()` at every call site.

## Wrapping

`wrap` is `null` globally — responses are unwrapped. Per-object overrides are `->wrap('data')` and
`->withoutWrapping()`; there is no class-level default-wrap hook. If the API wants an envelope, publish
`config/data.php` and set `wrap` there once, rather than calling `->wrap()` in every controller.

## Name mapping

`name_mapping_strategy` is `null` for both input and output, so property names go out as written (camelCase).
If the API should emit snake_case, use `#[MapOutputName(SnakeCaseMapper::class)]` on the class — or set the
global output strategy and record the decision. Don't mix per-class and global mapping.

`#[MapName(...)]` sets input and output together; `#[MapInputName]` alone is the right tool for consuming
snake_case webhook payloads without changing what the app emits.

## Appending computed output

`#[Computed]` marks a property calculated in the constructor and included in output. Note
`features.ignore_exception_when_trying_to_set_computed_property_value` is `false` — passing a value for a
computed property throws rather than being ignored, which is the useful behaviour; don't flip it to paper over
a payload that shouldn't contain the key.

For values that depend on request state rather than the object, use the `AppendableData` `with()` method.

## Depth guard

`max_transformation_depth` is `null` (unlimited) with `throw_when_max_transformation_depth_reached` at `true`.
A recursive relation chain will loop until memory runs out. If self-referencing Data ever ships, publish
`config/data.php` and set a depth there at that point.
