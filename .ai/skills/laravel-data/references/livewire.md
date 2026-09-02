# Livewire components

Read `../SKILL.md` first. Package source: `vendor/spatie/laravel-data/src/Support/Livewire/`
(`LivewireDataSynth`, `LivewireDataCollectionSynth`).

`config/data.php` sets `livewire.enable_synths` to `true`, so a Data object is a first-class public property —
no `toArray()`/`from()` round-tripping, no `Wireable` interface to implement.

## Data object as component state

```php
use App\Data\Automation\RuleConditionData;

class EditAutomationRule extends Component
{
    public RuleConditionData $condition;

    public function mount(AutomationRule $rule): void
    {
        $this->condition = RuleConditionData::from($rule->condition);
    }
}
```

```blade
<flux:input wire:model="condition.field" :label="__('automation.field')" />
<flux:select wire:model.live="condition.operator" :label="__('automation.operator')" />
```

Dotted `wire:model` paths reach into the Data object, including nested Data (`condition.range.start`) and
collections (`conditions.0.field`).

## `readonly` is the one thing to get right

A property bound with `wire:model` is written by the synth on hydration, so it **cannot be `readonly`**. Data
classes used purely as Action I/O or payloads should stay fully `readonly`; a Data class used as bindable
component state drops it on the bound properties only.

```php
class RuleConditionData extends Data
{
    public function __construct(
        public string $field,           // bound in the form — mutable
        public string $operator,        // bound — mutable
        public readonly string $id,     // never edited in the UI
    ) {}
}
```

If the same shape is both edited in a form and passed around internally, keep one mutable Data class rather
than two near-identical ones; the internal callers simply don't mutate it.

## Collections of Data

```php
use Spatie\LaravelData\DataCollection;

/** @var DataCollection<int, RuleConditionData> */
public DataCollection $conditions;

public function addCondition(): void
{
    $this->conditions[] = new RuleConditionData(field: '', operator: 'equals', value: null);
}

public function removeCondition(int $index): void
{
    unset($this->conditions[$index]);
}
```

`DataCollection` implements `ArrayAccess`, so `[]`/`unset()` work directly and
`LivewireDataCollectionSynth` re-hydrates elements as Data objects. Use the `DataCollection` wrapper here, not
a plain `array` with `#[DataCollectionOf]` — the array form hydrates back as arrays in a component context.

Always key repeatable rows in Blade (`wire:key`) — same rule as any Livewire loop, see `.ai/rules/pages.md`.

## Validating in a component

Component validation is Livewire's, not the package's — `$this->validate()` does not know about the Data
class's rules. Two workable patterns:

**Validate the Data class on submit** (preferred — one source of rules):

```php
public function save(): void
{
    $validated = RuleConditionData::validateAndCreate($this->condition->toArray());

    app(UpdateAutomationRule::class)->handle($this->rule, $validated);
}
```

`ValidationException` from `validateAndCreate` is caught by Livewire and populates the error bag with the same
dotted keys the form uses (`condition.operator` if you prefix consistently — pass
`['condition' => $this->condition->toArray()]` through a wrapping Data class when the keys must nest).

**Mirror rules with `#[Validate]`** only for cheap live feedback on a single field. Don't duplicate the whole
rule set — the Data class stays authoritative, and drift between the two is the failure mode.

## What not to do

- Don't make a Data object a `#[Computed]` Livewire property expecting it to persist — computed values are
  recalculated per request and never hydrate.
- Don't bind `wire:model` to a property typed `Optional|string`. `Optional` is an input-absence marker, not a
  form value; the synth has nothing to bind. Use a nullable property in the form Data class and convert to
  `Optional` at the boundary if the update needs partial semantics.
