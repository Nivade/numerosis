# Tables

## Contents

- Introduction
- Defining columns
- Column types
- Sorting and searching
- Filters
- Filter types
- Table actions
- Bulk actions
- Pagination
- Record URLs
- Reordering records
- Row grouping
- Summaries
- Empty state
- Polling and deferred loading
- Global settings
- Utility injection

## Introduction

Tables display, filter, and act on collections of records. They are used inside resources, relation managers, custom pages, and standalone Livewire components. All table configuration is declarative PHP.

## Defining columns

Columns are defined in `$table->columns([])`. Column classes live in `Filament\Tables\Columns`.

```php
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

public static function configure(Table $table): Table
{
  return $table
    ->columns([
      TextColumn::make('name')
        ->searchable()
        ->sortable(),
      TextColumn::make('email')
        ->searchable(),
      TextColumn::make('created_at')
        ->dateTime()
        ->sortable()
        ->toggleable(isToggledHiddenByDefault: true),
    ]);
}
```

## Column types

| Class              | Purpose                                |
|--------------------|----------------------------------------|
| `TextColumn`       | Display text, dates, numbers, badges   |
| `IconColumn`       | Display icons based on state           |
| `ImageColumn`      | Display images/avatars                 |
| `ColorColumn`      | Display color swatches                 |
| `SelectColumn`     | Inline editable select dropdown        |
| `ToggleColumn`     | Inline editable toggle switch          |
| `TextInputColumn`  | Inline editable text input             |
| `CheckboxColumn`   | Inline editable checkbox               |

### Accessing related data

Use dot notation for relationship data:

```php
TextColumn::make('author.name'),
```

### Counting relationships

```php
TextColumn::make('comments_count')
  ->counts('comments'),
```

### Aggregating relationships

```php
TextColumn::make('orders_avg_price')
  ->avg('orders', 'price')
  ->money('EUR'),
```

### Column formatting

```php
TextColumn::make('price')
  ->money('EUR'),

TextColumn::make('status')
  ->badge()
  ->color(fn (string $state): string => match ($state) {
    'draft' => 'gray',
    'reviewing' => 'warning',
    'published' => 'success',
    'rejected' => 'danger',
  }),

TextColumn::make('created_at')
  ->dateTime()
  ->since()
  ->dateTimeTooltip(),
```

### Toggleable columns

```php
TextColumn::make('email')
  ->toggleable(),

TextColumn::make('updated_at')
  ->toggleable(isToggledHiddenByDefault: true),
```

## Sorting and searching

### Making columns sortable

```php
TextColumn::make('name')
  ->sortable(),
```

### Default sort

```php
$table->defaultSort('created_at', 'desc'),
```

### Making columns searchable

```php
TextColumn::make('name')
  ->searchable(),
```

Multiple searchable columns are searched simultaneously. You can also search individually:

```php
TextColumn::make('name')
  ->searchable(isIndividual: true),
```

## Filters

Filters narrow down displayed records. Defined in `$table->filters([])`.

```php
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Illuminate\Database\Eloquent\Builder;

$table->filters([
  Filter::make('is_active')
    ->query(fn (Builder $query) => $query->where('is_active', true))
    ->toggle(),

  SelectFilter::make('status')
    ->options([
      'draft' => 'Draft',
      'published' => 'Published',
    ]),

  TernaryFilter::make('email_verified')
    ->nullable(),
]);
```

## Filter types

| Class              | Purpose                                        |
|--------------------|------------------------------------------------|
| `Filter`           | Custom query filter, supports toggle mode      |
| `SelectFilter`     | Dropdown selection filter                      |
| `TernaryFilter`    | True / false / null ternary toggle             |
| `QueryBuilder`     | Advanced query builder with multiple operators |

### Filter layout options

```php
use Filament\Tables\Enums\FiltersLayout;

$table->filtersLayout(FiltersLayout::AboveContent)
$table->filtersLayout(FiltersLayout::AboveContentCollapsible)
$table->filtersLayout(FiltersLayout::BelowContent)
$table->filtersLayout(FiltersLayout::Dropdown) // default
```

### Persist filters in session

```php
$table->persistFiltersInSession(),
```

### Filter form columns

```php
$table->filtersFormColumns(3),
```

## Table actions

### Record actions

Appear at the end of each row:

```php
use Filament\Actions\EditAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;

$table->recordActions([
  ViewAction::make(),
  EditAction::make(),
  DeleteAction::make(),
]),
```

### Positioning record actions before columns

```php
use Filament\Tables\Enums\RecordActionsPosition;

$table->recordActionsPosition(RecordActionsPosition::BeforeCells),
```

### Header actions

Appear in the table header:

```php
use Filament\Actions\CreateAction;

$table->headerActions([
  CreateAction::make(),
]),
```

### Toolbar actions

Appear in the table toolbar alongside bulk actions:

```php
$table->toolbarActions([
  BulkActionGroup::make([
    DeleteBulkAction::make(),
  ]),
]),
```

### Column actions

Actions attached to a specific column cell:

```php
TextColumn::make('title')
  ->action(fn (Post $record) => $record->archive()),
```

### Grouping actions

```php
use Filament\Actions\ActionGroup;

$table->recordActions([
  ActionGroup::make([
    ViewAction::make(),
    EditAction::make(),
    DeleteAction::make(),
  ]),
]),
```

## Bulk actions

Bulk actions execute when records are selected. They appear in a `BulkActionGroup`:

```php
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Illuminate\Database\Eloquent\Collection;

$table->toolbarActions([
  BulkActionGroup::make([
    BulkAction::make('delete')
      ->requiresConfirmation()
      ->action(fn (Collection $records) => $records->each->delete())
      ->deselectRecordsAfterCompletion(),
  ]),
]),
```

### Authorizing bulk actions

```php
DeleteBulkAction::make()
  ->authorizeIndividualRecords(),
```

### Disabling bulk actions for specific rows

```php
$table->checkIfRecordIsSelectableUsing(
  fn (Model $record): bool => $record->status !== 'locked',
),
```

## Pagination

```php
$table
  ->paginationPageOptions([10, 25, 50, 100])
  ->defaultPaginationPageOption(25)
  ->extremePaginationLinks(),
```

### Simple pagination

```php
$table->simplePagination(),
```

### Cursor pagination

```php
$table->cursorPagination(),
```

### Disabling pagination

```php
$table->paginated(false),
```

## Record URLs (clickable rows)

```php
$table->recordUrl(
  fn (Model $record): string => route('posts.show', ['post' => $record]),
),
```

## Reordering records

Enable drag-and-drop reordering:

```php
$table->reorderable('sort_order'),
```

## Row grouping

```php
use Filament\Tables\Grouping\Group;

$table
  ->groups([
    Group::make('status')
      ->collapsible(),
    Group::make('category.name')
      ->label('Category'),
  ])
  ->defaultGroup('status'),
```

## Summaries

Add summary rows to columns:

```php
use Filament\Tables\Columns\Summarizers\Average;
use Filament\Tables\Columns\Summarizers\Sum;
use Filament\Tables\Columns\Summarizers\Count;

TextColumn::make('price')
  ->money('EUR')
  ->summarize([
    Average::make()->label('Average price'),
    Sum::make()->label('Total'),
  ]),

TextColumn::make('id')
  ->summarize(Count::make()),
```

## Empty state

Customize the empty state:

```php
$table
  ->emptyStateHeading('No customers yet')
  ->emptyStateDescription('Create your first customer to get started.')
  ->emptyStateIcon('heroicon-o-users')
  ->emptyStateActions([
    CreateAction::make(),
  ]),
```

## Polling and deferred loading

```php
$table->poll('10s'),
$table->deferLoading(),
```

## Global settings

Configure defaults for all tables in a service provider:

```php
use Filament\Tables\Table;

Table::configureUsing(function (Table $table): void {
  $table
    ->paginationPageOptions([10, 25, 50])
    ->defaultPaginationPageOption(25);
});
```

## Utility injection

All callback methods support dependency injection via parameter names:

| Parameter      | Injected value                     |
|----------------|------------------------------------|
| `$state`       | Current column value               |
| `$record`      | Current Eloquent model             |
| `$rowLoop`     | Blade loop variable for current row|
| `$livewire`    | Current Livewire component         |
| `$component`   | Current column instance            |
| `$table`       | Current table instance             |

```php
TextColumn::make('status')
  ->color(fn (string $state): string => match ($state) {
    'active' => 'success',
    default => 'gray',
  }),
```

## Common pitfalls

- Using `->actions()` instead of `->recordActions()` / `->toolbarActions()` (v5 API change)
- Forgetting `->searchable()` when expecting global search to work
- Not adding `->sortable()` for columns that need sorting
- Using complex computed columns without a custom sort query
- Filter queries that conflict with soft-delete scopes
