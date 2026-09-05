# Forms

## Contents

- Introduction
- Available field types
- Field configuration
- Validation
- Reactivity and live fields
- State management
- Dependent fields
- Field actions
- Repeaters
- Builder fields
- File uploads
- Select fields
- Utility injection
- Global settings

## Introduction

Filament Forms provide composable, state-aware input components for collecting user data. Forms are used in resource create/edit pages, action modals, table filters, and standalone Livewire components. Field classes live in `Filament\Forms\Components`.

## Available field types

| Class               | Purpose                                    |
|---------------------|--------------------------------------------|
| `TextInput`         | Text, email, password, numeric, URL, tel   |
| `Select`            | Dropdown select with search, multi-select  |
| `Checkbox`          | Single boolean checkbox                    |
| `Toggle`            | Toggle switch                              |
| `CheckboxList`      | Multiple checkboxes                        |
| `Radio`             | Radio button group                         |
| `DateTimePicker`    | Date, time, or datetime picker             |
| `FileUpload`        | File and image uploads                     |
| `RichEditor`        | WYSIWYG rich text editor                   |
| `MarkdownEditor`    | Markdown editor with preview               |
| `Repeater`          | Repeatable groups of fields                |
| `Builder`           | Block-based content builder                |
| `TagsInput`         | Tag input with autocomplete                |
| `Textarea`          | Multi-line text area                       |
| `KeyValue`          | Key-value pair editor                      |
| `ColorPicker`       | Color selection                            |
| `ToggleButtons`     | Button group toggle                        |
| `Slider`            | Range slider                               |
| `CodeEditor`        | Code editor with syntax highlighting       |
| `Hidden`            | Hidden field                               |

## Field configuration

### Common methods

```php
TextInput::make('name')
  ->label('Full Name')
  ->placeholder('Enter your name')
  ->helperText('Your legal name')
  ->hint('Required')
  ->hintIcon('heroicon-o-information-circle')
  ->required()
  ->disabled()
  ->hidden()
  ->default('John Doe')
  ->columnSpan(2)
  ->columnSpanFull()
  ->inlineLabel(),
```

### Text input variants

```php
TextInput::make('email')->email(),
TextInput::make('price')->numeric()->prefix('€'),
TextInput::make('password')->password()->revealable(),
TextInput::make('phone')->tel(),
TextInput::make('website')->url(),
TextInput::make('slug')->alphaDash(),
```

## Validation

Validation is defined inline on fields:

```php
TextInput::make('title')
  ->required()
  ->minLength(3)
  ->maxLength(255)
  ->unique(ignoreRecord: true)
  ->rules(['alpha_dash']),

TextInput::make('email')
  ->email()
  ->required()
  ->unique(table: User::class, column: 'email', ignoreRecord: true),

TextInput::make('slug')
  ->alphaDash()
  ->unique(ignoreRecord: true),
```

### Custom validation rules

```php
TextInput::make('code')
  ->rules([
    fn (): Closure => function (string $attribute, $value, Closure $fail) {
      if ($value === 'foo') {
        $fail('The :attribute is invalid.');
      }
    },
  ]),
```

## Reactivity and live fields

Mark a field as `live()` to trigger server-side re-rendering on change:

```php
Select::make('type')
  ->options([
    'employee' => 'Employee',
    'freelancer' => 'Freelancer',
  ])
  ->live(),
```

### `afterStateUpdated()`

Runs after a `live()` field changes. Only triggers from user input, not from `$set()`:

```php
TextInput::make('name')
  ->live()
  ->afterStateUpdated(function (?string $state, ?string $old) {
    // $state = new value, $old = previous value
  }),
```

### Live debounce

```php
TextInput::make('search')
  ->live(debounce: 500),
```

### Live on blur

```php
TextInput::make('title')
  ->live(onBlur: true),
```

### `afterStateUpdatedJs()` (new in v5)

Executes JavaScript client-side when a field's state changes, without a network request. Useful for simple derived values that don't need server-side logic:

```php
use Filament\Forms\Components\TextInput;

TextInput::make('name')
  ->afterStateUpdatedJs(<<<'JS'
    $set('email', ($state ?? '').replaceAll(' ', '.').toLowerCase() + '@example.com')
  JS),

TextInput::make('email')
  ->label('Email address'),
```

Available JS utilities: `$state` (current value), `$get('field')`, `$set('field', value)`.

> Use `afterStateUpdatedJs()` instead of `->live()->afterStateUpdated()` when the transformation is purely client-side to avoid unnecessary network requests.

## State management

### Getting and setting state

Use `Get` and `Set` utilities via parameter injection:

```php
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;

TextInput::make('name')
  ->live()
  ->afterStateUpdated(function (Get $get, Set $set, ?string $state) {
    $set('slug', Str::slug($state));
  }),
```

### Conditional visibility

```php
Toggle::make('is_featured')
  ->live(),

Textarea::make('featured_description')
  ->maxLength(500)
  ->visible(fn (Get $get): bool => $get('is_featured')),
```

## Dependent fields

Dynamic schemas based on another field's value:

```php
Select::make('type')
  ->options([
    'employee' => 'Employee',
    'freelancer' => 'Freelancer',
  ])
  ->live()
  ->afterStateUpdated(fn (Select $component) => $component
    ->getContainer()
    ->getComponent('dynamicTypeFields')
    ->getChildSchema()
    ->fill()),

Grid::make(2)
  ->schema(fn (Get $get): array => match ($get('type')) {
    'employee' => [
      TextInput::make('employee_number')->required(),
      FileUpload::make('badge')->image()->required(),
    ],
    'freelancer' => [
      TextInput::make('hourly_rate')->numeric()->required()->prefix('€'),
      FileUpload::make('contract')->required(),
    ],
    default => [],
  })
  ->key('dynamicTypeFields'),
```

## Field actions

Actions can be attached to fields as prefix, suffix, or hint actions:

```php
TextInput::make('cost')
  ->prefix('€')
  ->suffixAction(
    Action::make('calculate')
      ->icon('heroicon-o-calculator')
      ->action(function (Set $set, Get $get) {
        $set('cost', calculateCost($get('quantity')));
      }),
  ),

TextInput::make('name')
  ->hintAction(
    Action::make('generateSlug')
      ->icon('heroicon-o-arrow-path')
      ->action(function (Set $set, $state) {
        $set('slug', Str::slug($state));
      }),
  ),
```

### Multiple actions

```php
TextInput::make('cost')
  ->prefixActions([
    Action::make('...'),
    Action::make('...'),
  ])
  ->suffixActions([
    Action::make('...'),
  ]),
```

## Repeaters

Repeatable groups of fields for hasMany or array data:

```php
use Filament\Forms\Components\Repeater;

Repeater::make('addresses')
  ->schema([
    TextInput::make('street')->required(),
    TextInput::make('city')->required(),
    TextInput::make('postal_code')->required(),
  ])
  ->columns(3)
  ->minItems(1)
  ->maxItems(5)
  ->collapsible()
  ->cloneable()
  ->reorderable()
  ->defaultItems(1),
```

### Relationship repeaters

```php
Repeater::make('addresses')
  ->relationship()
  ->schema([/* ... */]),
```

## Builder fields

Block-based content building:

```php
use Filament\Forms\Components\Builder;
use Filament\Forms\Components\Builder\Block;

Builder::make('content')
  ->blocks([
    Block::make('heading')
      ->schema([
        TextInput::make('content')->required(),
        Select::make('level')
          ->options([
            'h2' => 'Heading 2',
            'h3' => 'Heading 3',
          ]),
      ]),
    Block::make('paragraph')
      ->schema([
        RichEditor::make('content')->required(),
      ]),
    Block::make('image')
      ->schema([
        FileUpload::make('url')->image()->required(),
        TextInput::make('alt')->required(),
      ]),
  ]),
```

## File uploads

```php
FileUpload::make('avatar')
  ->image()
  ->imageEditor()
  ->avatar()
  ->directory('avatars')
  ->maxSize(1024)
  ->acceptedFileTypes(['image/jpeg', 'image/png']),

FileUpload::make('attachments')
  ->multiple()
  ->directory('attachments')
  ->maxFiles(5),
```

## Select fields

```php
Select::make('author_id')
  ->relationship('author', 'name')
  ->searchable()
  ->preload()
  ->createOptionForm([
    TextInput::make('name')->required(),
    TextInput::make('email')->email()->required(),
  ]),

Select::make('tags')
  ->multiple()
  ->relationship('tags', 'name')
  ->preload(),
```

## Utility injection

All field callbacks support parameter injection:

| Parameter    | Injected value                           |
|--------------|------------------------------------------|
| `$state`     | Current field value                      |
| `$old`       | Previous value (in `afterStateUpdated`)  |
| `$record`    | Current Eloquent model                   |
| `$get`       | `Get` utility for reading sibling state  |
| `$set`       | `Set` utility for writing sibling state  |
| `$livewire`  | Current Livewire component instance      |
| `$component` | Current field instance                   |
| `$operation` | Current operation: `create` or `edit`    |

## Global settings

Configure defaults for all instances of a field:

```php
use Filament\Forms\Components\TextInput;

TextInput::configureUsing(function (TextInput $input): void {
  $input->maxLength(255);
});
```

## Common pitfalls

- Using `live()` on every field causes excessive re-renders — only use where reactivity is needed
- `afterStateUpdated()` only fires from user input, not from `$set()` calls
- Missing `->relationship()` on Repeaters causes data loss on save
- Using `$get()` without `live()` on the source field returns stale state
- Forgetting `ignoreRecord: true` on unique validation causes edit form to fail
