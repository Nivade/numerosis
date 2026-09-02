# Schema Layouts

## Contents

- Introduction
- Grid system
- Grid component
- Flex component
- Section component
- Fieldset component
- Tabs component
- Wizard component
- Callout component
- Empty state component
- Column span and ordering
- Container queries
- Responsive breakpoints
- Global settings

## Introduction

Schema layout components organize forms and infolists into structured, responsive layouts. In Filament v5, layout components live in `Filament\Schemas\Components`. They are shared between forms, infolists, and action modals.

All layout components support the `columns()` method for responsive grid layouts and can be infinitely nested.

## Grid system

Every layout component has a built-in grid system via `columns()`:

```php
use Filament\Schemas\Components\Section;

Section::make('Details')
  ->columns(2)
  ->schema([
    TextInput::make('name'),
    TextInput::make('email'),
  ]),
```

### Responsive columns

Pass an array to set different column counts per breakpoint:

```php
Section::make('Details')
  ->columns([
    'sm' => 2,
    'xl' => 4,
    '2xl' => 6,
  ])
  ->schema([/* ... */]),
```

Tailwind breakpoints: `sm`, `md`, `lg`, `xl`, `2xl`. Default (no breakpoint) = 1 column.

### Column span

Control how many columns a component occupies:

```php
TextInput::make('description')
  ->columnSpan(2),

TextInput::make('content')
  ->columnSpanFull(),

TextInput::make('name')
  ->columnSpan([
    'default' => 1,
    'sm' => 2,
    'xl' => 3,
  ]),
```

## Grid component

An explicit grid wrapper with no extra styling:

```php
use Filament\Schemas\Components\Grid;

Grid::make(2)
  ->schema([
    TextInput::make('first_name'),
    TextInput::make('last_name'),
  ]),

Grid::make([
  'sm' => 2,
  'lg' => 3,
])
  ->schema([/* ... */]),
```

## Flex component

A flexbox-based layout (new in v5) that uses CSS flexbox instead of the grid system, allowing sections to grow or stack based on screen size:

```php
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Flex;

Flex::make([
  Section::make([
    TextInput::make('title'),
    Textarea::make('content'),
  ]),
  Section::make([
    Toggle::make('is_published'),
    Toggle::make('is_featured'),
  ])->grow(false),
])->from('md'),
```

### Key Flex methods

| Method        | Purpose                                               |
|---------------|-------------------------------------------------------|
| `->from()`    | Breakpoint at which flex layout activates (`'md'`, `'lg'`, etc.). Stacks vertically below the breakpoint. |
| `->grow(false)` | Prevents a child component from growing to fill remaining space. |

## Section component

Groups fields with a heading, description, and optional border:

```php
use Filament\Schemas\Components\Section;

Section::make('Personal Information')
  ->description('Enter your personal details below')
  ->schema([
    TextInput::make('name'),
    TextInput::make('email'),
  ])
  ->columns(2)
  ->collapsible()
  ->collapsed()
  ->compact()
  ->icon('heroicon-o-user'),
```

### Aside sections

Display a section alongside content in a two-column layout:

```php
Section::make('Notifications')
  ->description('Configure notification preferences')
  ->aside()
  ->schema([/* ... */]),
```

### Persisting collapsed state

```php
Section::make('Advanced')
  ->collapsible()
  ->persistCollapsed(),
```

## Fieldset component

Groups fields with a label and border:

```php
use Filament\Schemas\Components\Fieldset;

Fieldset::make('Address')
  ->schema([
    TextInput::make('street'),
    TextInput::make('city'),
    TextInput::make('postal_code'),
  ])
  ->columns(3),
```

## Tabs component

Organize content into tabbed views:

```php
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;

Tabs::make('Settings')
  ->tabs([
    Tab::make('General')
      ->icon('heroicon-o-cog-6-tooth')
      ->schema([/* ... */]),
    Tab::make('Notifications')
      ->icon('heroicon-o-bell')
      ->badge(5)
      ->schema([/* ... */]),
    Tab::make('Security')
      ->icon('heroicon-o-lock-closed')
      ->schema([/* ... */]),
  ]),
```

### Default active tab

```php
Tabs::make('Settings')
  ->activeTab(2)
  ->tabs([/* ... */]),
```

### Vertical tabs

```php
Tabs::make('Settings')
  ->vertical()
  ->tabs([/* ... */]),
```

### Persist active tab

```php
Tabs::make('Settings')
  ->persistTabInSession()
  ->tabs([/* ... */]),

// Or in the URL query string:
Tabs::make('Settings')
  ->persistTabInQueryString()
  ->tabs([/* ... */]),
```

### Tab icon position

```php
Tab::make('General')
  ->icon('heroicon-o-cog-6-tooth')
  ->iconPosition(IconPosition::After),
```

### Grid columns within tabs

```php
Tab::make('Details')
  ->columns(2)
  ->schema([/* ... */]),
```

## Wizard component

Multi-step form flows:

```php
use Filament\Schemas\Components\Wizard;
use Filament\Schemas\Components\Wizard\Step;

Wizard::make([
  Step::make('Account')
    ->description('Create your account')
    ->schema([
      TextInput::make('email')->email()->required(),
      TextInput::make('password')->password()->required(),
    ])
    ->columns(2),
  Step::make('Profile')
    ->description('Complete your profile')
    ->schema([
      TextInput::make('name')->required(),
      FileUpload::make('avatar')->image(),
    ]),
  Step::make('Confirm')
    ->description('Review and confirm')
    ->schema([
      Checkbox::make('terms')->accepted()->required(),
    ]),
])
  ->submitAction(view('filament.wizard.submit-button')),
```

### Skippable steps

```php
Step::make('Optional Info')
  ->schema([/* ... */])
  ->skippable(),
```

## Callout component

Display informational callouts (new in v5):

```php
use Filament\Schemas\Components\Callout;

Callout::make()
  ->info()
  ->title('Note')
  ->content('This action cannot be undone.'),

Callout::make()
  ->warning()
  ->content('Please double-check your input.'),
```

## Empty state component

Display a placeholder when no content is available (new in v5):

```php
use Filament\Schemas\Components\EmptyState;

EmptyState::make()
  ->heading('No items')
  ->description('Create your first item to get started.')
  ->icon('heroicon-o-inbox'),
```

## Column ordering

Control the visual order of components across breakpoints:

```php
Grid::make(['sm' => 2, 'lg' => 3])
  ->schema([
    TextInput::make('title')
      ->columnOrder([
        'default' => 1,
        'lg' => 3,
      ]),
    TextInput::make('description')
      ->columnOrder([
        'default' => 2,
        'lg' => 1,
      ]),
    TextInput::make('category')
      ->columnOrder([
        'default' => 3,
        'lg' => 2,
      ]),
  ]),
```

## Container queries

Use container-query breakpoints instead of viewport breakpoints:

```php
Grid::make()
  ->gridContainer()
  ->columns([
    '@md' => 3,
    '@xl' => 4,
  ])
  ->schema([
    TextInput::make('name')
      ->columnSpan([
        '@md' => 2,
        '@xl' => 3,
      ]),
  ]),
```

Container query breakpoints: `@xs`, `@sm`, `@md`, `@lg`, `@xl`, `@2xl`, etc.

## Common pitfalls

- Forgetting `->schema([])` on layout components (they won't render without children)
- **`Grid`, `Section`, and `Fieldset` no longer span all columns by default in v5** — they consume only one column. Use `->columnSpanFull()` explicitly when full-width is needed
- Using viewport breakpoints when component is in a sidebar (use container queries instead)
- Nesting too many layout levels — keep structures flat where possible
- Not using `->columnSpanFull()` for fields that need to span the entire grid width
- Forgetting that `Grid::make(2)` is equivalent to `Grid::make()->columns(2)`
