# Infolists

## Contents

- Introduction
- Available entry types
- Entry configuration
- Formatting
- Relationships and dot notation
- Layout within infolists
- Actions on entries
- Utility injection
- Global settings

## Introduction

Infolists render read-only data for a specific entity. They are used on resource View pages, in action modals, relation managers, and standalone Livewire components. Entry classes live in `Filament\Infolists\Components`.

Infolists share the same schema/layout system as forms. The key difference: **entries display data, fields collect data**.

## Available entry types

| Class              | Purpose                                    |
|--------------------|--------------------------------------------|
| `TextEntry`        | Display text, dates, numbers, badges       |
| `IconEntry`        | Display icons based on state               |
| `ImageEntry`       | Display images                             |
| `ColorEntry`       | Display color swatches                     |
| `CodeEntry`        | Display code blocks                        |
| `KeyValueEntry`    | Display key-value pairs                    |
| `RepeatableEntry`  | Display repeatable groups of entries       |

## Entry configuration

### Common methods

```php
use Filament\Infolists\Components\TextEntry;

TextEntry::make('name')
  ->label('Full Name')
  ->placeholder('No name provided')
  ->hidden()
  ->visible(fn () => true)
  ->columnSpan(2)
  ->columnSpanFull()
  ->inlineLabel()
  ->tooltip('This is the user\'s legal name')
  ->copyable()
  ->copyMessage('Copied!')
  ->copyMessageDuration(1500),
```

### Badge display

```php
TextEntry::make('status')
  ->badge()
  ->color(fn (string $state): string => match ($state) {
    'draft' => 'gray',
    'reviewing' => 'warning',
    'published' => 'success',
    'rejected' => 'danger',
  }),
```

### Icons

```php
IconEntry::make('is_active')
  ->boolean(),

IconEntry::make('status')
  ->icon(fn (string $state): string => match ($state) {
    'draft' => 'heroicon-o-pencil',
    'reviewing' => 'heroicon-o-clock',
    'published' => 'heroicon-o-check-circle',
  }),
```

### Images

```php
ImageEntry::make('avatar')
  ->circular()
  ->size(40),
```

## Formatting

### Date and time

```php
TextEntry::make('created_at')
  ->dateTime(),

TextEntry::make('created_at')
  ->date(),

TextEntry::make('created_at')
  ->since()
  ->dateTimeTooltip(),

TextEntry::make('event_time')
  ->time(),
```

### Numbers and currency

```php
TextEntry::make('stock')
  ->numeric()
  ->decimalPlaces(0),

TextEntry::make('price')
  ->money('EUR'),

TextEntry::make('price')
  ->money('EUR', divideBy: 100),
```

### Number locale

```php
use Filament\Infolists\Infolist;

Infolist::$defaultNumberLocale = 'nl';
```

### URLs

```php
TextEntry::make('website')
  ->url(fn ($state): string => $state)
  ->openUrlInNewTab(),

TextEntry::make('title')
  ->url(fn (Post $record): string => PostResource::getUrl('edit', ['record' => $record])),
```

### Lists and separated values

```php
TextEntry::make('tags.name')
  ->badge()
  ->separator(','),

TextEntry::make('tags.name')
  ->listWithLineBreaks()
  ->bulleted(),
```

### Limiting text

```php
TextEntry::make('description')
  ->words(20),

TextEntry::make('description')
  ->characterLimit(100),
```

### HTML rendering

```php
TextEntry::make('content')
  ->html(),

TextEntry::make('content')
  ->markdown(),
```

## Relationships and dot notation

Access related model data via dot notation:

```php
TextEntry::make('author.name'),
TextEntry::make('category.parent.name'),
```

### Repeatable entries for hasMany

```php
use Filament\Infolists\Components\RepeatableEntry;

RepeatableEntry::make('comments')
  ->schema([
    TextEntry::make('author.name'),
    TextEntry::make('content'),
    TextEntry::make('created_at')->dateTime(),
  ])
  ->columns(3),
```

## Layout within infolists

Infolists use the same layout components as forms (see schema-layouts reference):

```php
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;

Section::make('Customer Details')
  ->schema([
    TextEntry::make('name'),
    TextEntry::make('email'),
  ])
  ->columns(2),

Tabs::make('Tabs')
  ->tabs([
    Tab::make('Details')
      ->schema([
        TextEntry::make('name'),
        TextEntry::make('email'),
      ]),
    Tab::make('Orders')
      ->schema([
        RepeatableEntry::make('orders')
          ->schema([/* ... */]),
      ]),
  ]),
```

### Inline labels

```php
Section::make('Details')
  ->inlineLabel()
  ->schema([
    TextEntry::make('name'),
    TextEntry::make('email')->label('Email address'),
  ]),
```

## Actions on entries

Entries can have actions attached:

```php
use Filament\Actions\Action;

TextEntry::make('name')
  ->suffixAction(
    Action::make('copy')
      ->icon('heroicon-o-clipboard')
      ->action(fn () => /* copy logic */),
  ),
```

### Below-content schemas (v5)

```php
use Filament\Schemas\Schema;
use Filament\Schemas\Components\Icon;
use Filament\Support\Icons\Heroicon;

TextEntry::make('name')
  ->belowContent(Schema::end([
    Icon::make(Heroicon::InformationCircle),
    'This is the user\'s full name.',
    Action::make('generate'),
  ])),
```

## Utility injection

All entry callbacks support parameter injection:

| Parameter    | Injected value                      |
|--------------|-------------------------------------|
| `$state`     | Current entry value                 |
| `$record`    | Current Eloquent model              |
| `$livewire`  | Current Livewire component instance |
| `$component` | Current entry instance              |

## Global settings

```php
use Filament\Infolists\Components\TextEntry;

TextEntry::configureUsing(function (TextEntry $entry): void {
  $entry->placeholder('—');
});
```

## Common pitfalls

- Using form fields (`TextInput`) instead of infolist entries (`TextEntry`) in view pages
- Forgetting `->columns(2)` on sections — infolists in panels default to 2 columns, standalone infolists default to 1
- Not using `->html()` when entry value contains HTML
- Formatting methods are for presentation only — never use them to mutate data
