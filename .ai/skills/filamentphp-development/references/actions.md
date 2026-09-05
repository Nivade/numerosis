# Actions

## Contents

- Introduction
- Creating actions
- Action placement
- Modals and confirmation
- Modal forms
- Wizard steps in modals
- Action lifecycle hooks
- Authorization and visibility
- Prebuilt actions
- Import and export actions
- Grouping actions
- Styling actions
- Rate limiting
- Utility injection

## Introduction

Actions are button-driven workflows in Filament. They handle user interactions like deleting a record, opening a modal with a form, triggering a background job, or confirming a destructive operation. Actions can appear on pages, tables, schemas, forms, and infolists.

All action classes live in `Filament\Actions`.

## Creating actions

```php
use Filament\Actions\Action;

Action::make('archive')
  ->label('Archive post')
  ->icon('heroicon-o-archive-box')
  ->color('warning')
  ->requiresConfirmation()
  ->action(fn (Post $record) => $record->archive()),
```

### Key methods

| Method                    | Purpose                                      |
|---------------------------|----------------------------------------------|
| `->label()`               | Button text                                  |
| `->icon()`                | Heroicon identifier                          |
| `->color()`               | `primary`, `danger`, `warning`, `success`, `gray`, `info` |
| `->size()`                | `Size::Small`, `Size::Medium`, `Size::Large` |
| `->url()`                 | Redirect to URL instead of running action    |
| `->action()`              | Closure executed when action is triggered    |
| `->requiresConfirmation()`| Show confirmation dialog before execution    |
| `->disabled()`            | Disable the button                           |
| `->hidden()` / `->visible()` | Control visibility                       |
| `->tooltip()`             | Hover tooltip                                |
| `->badge()`               | Badge on the button                          |
| `->extraAttributes()`     | Additional HTML attributes                   |

## Action placement

### Page actions

Header actions on custom pages or resource pages:

```php
protected function getHeaderActions(): array
{
  return [
    Action::make('export')
      ->action(fn () => $this->export()),
  ];
}
```

### Table record actions

Actions on individual table rows (see tables reference):

```php
$table->recordActions([
  EditAction::make(),
  DeleteAction::make(),
]),
```

### Table bulk actions

Actions on selected records:

```php
$table->toolbarActions([
  BulkActionGroup::make([
    DeleteBulkAction::make(),
  ]),
]),
```

### Schema/form/infolist component actions

Actions attached to fields or entries:

```php
TextInput::make('cost')
  ->suffixAction(
    Action::make('calculate')
      ->icon('heroicon-o-calculator')
      ->action(function (Set $set, Get $get) {
        $set('cost', calculateCost($get('quantity')));
      }),
  ),
```

## Modals and confirmation

### Simple confirmation

```php
Action::make('delete')
  ->requiresConfirmation()
  ->action(fn (Post $record) => $record->delete()),
```

### Customizing modal content

```php
Action::make('delete')
  ->requiresConfirmation()
  ->modalHeading('Delete post')
  ->modalDescription('Are you sure you\'d like to delete this post? This cannot be undone.')
  ->modalSubmitActionLabel('Yes, delete it')
  ->modalIcon('heroicon-o-trash')
  ->action(fn (Post $record) => $record->delete()),
```

### Adding child actions to modal footer

```php
Action::make('edit')
  ->schema([/* schema components */])
  ->modalFooterActions(fn (Action $action): array => [
    $action->getModalSubmitAction(),
    Action::make('delete')
      ->requiresConfirmation()
      ->action(fn () => /* ... */)
      ->cancelParentActions(),
    $action->getModalCancelAction(),
  ]),
```

## Modal forms

In v5, action modals use `->schema([])` (replaces the old `->form([])` from v3/v4):

```php
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Select;

Action::make('updateAuthor')
  ->fillForm(fn (Post $record): array => [
    'authorId' => $record->author->id,
  ])
  ->schema([
    Select::make('authorId')
      ->label('Author')
      ->options(User::query()->pluck('name', 'id'))
      ->required(),
  ])
  ->action(function (array $data, Post $record): void {
    $record->author()->associate($data['authorId']);
    $record->save();
  }),
```

### Rendering a schema in a modal

Modals can render full schemas mixing form fields and infolist entries using `->schema()`:

```php
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;

Action::make('viewUser')
  ->schema([
    Grid::make(2)
      ->schema([
        Section::make('Details')
          ->schema([
            TextInput::make('name'),
            Select::make('position')
              ->options([
                'developer' => 'Developer',
                'designer' => 'Designer',
              ]),
          ]),
        Section::make('Auditing')
          ->schema([
            TextEntry::make('created_at')->dateTime(),
            TextEntry::make('updated_at')->dateTime(),
          ]),
      ]),
  ]),
```

## Wizard steps in modals

Actions can use wizard steps instead of a single form:

```php
use Filament\Schemas\Components\Wizard\Step;

Action::make('create')
  ->steps([
    Step::make('Name')
      ->description('Give the category a unique name')
      ->schema([
        TextInput::make('name')
          ->required()
          ->live()
          ->afterStateUpdated(fn ($state, callable $set) => $set('slug', Str::slug($state))),
        TextInput::make('slug')
          ->disabled()
          ->required(),
      ])
      ->columns(2),
    Step::make('Description')
      ->schema([
        MarkdownEditor::make('description'),
      ]),
    Step::make('Visibility')
      ->schema([
        Toggle::make('is_visible')
          ->label('Visible to customers.')
          ->default(true),
      ]),
  ]),
```

## Action lifecycle hooks

Prebuilt CRUD actions expose lifecycle hooks:

```php
CreateAction::make()
  ->beforeFormFilled(function () { /* before defaults */ })
  ->afterFormFilled(function () { /* after defaults */ })
  ->beforeFormValidated(function () { /* before validation */ })
  ->afterFormValidated(function () { /* after validation */ })
  ->before(function () { /* before saving */ })
  ->after(function () { /* after saving */ }),
```

## Authorization and visibility

```php
Action::make('edit')
  ->visible(auth()->user()->can('update', $this->post)),

Action::make('edit')
  ->hidden(! auth()->user()->can('update', $this->post)),

Action::make('publish')
  ->authorize('publish', $this->post),
```

## Prebuilt actions

Filament provides ready-made actions for common operations:

| Action class          | Purpose                                   |
|-----------------------|-------------------------------------------|
| `CreateAction`        | Create a new record with a modal form     |
| `EditAction`          | Edit a record with a modal form           |
| `ViewAction`          | View a record in a read-only modal        |
| `DeleteAction`        | Delete a single record with confirmation  |
| `DeleteBulkAction`    | Bulk delete selected records              |
| `ForceDeleteAction`   | Force delete a soft-deleted record        |
| `ForceDeleteBulkAction` | Bulk force delete                      |
| `RestoreAction`       | Restore a soft-deleted record             |
| `RestoreBulkAction`   | Bulk restore soft-deleted records         |
| `ReplicateAction`     | Duplicate a record                        |
| `ImportAction`        | Import records from a CSV/XLSX file       |
| `ExportAction`        | Export records to a CSV/XLSX file         |

## Import and export actions

### Import

```bash
php artisan make:filament-importer Customer
```

Register in a table header:

```php
use Filament\Actions\ImportAction;
use App\Filament\Imports\CustomerImporter;

$table->headerActions([
  ImportAction::make()
    ->importer(CustomerImporter::class),
]),
```

**Prerequisites**: queue batches table (`php artisan make:queue-batches-table`) and notifications table (`php artisan make:notifications-table`).

### Export

```bash
php artisan make:filament-exporter Customer
```

```php
use Filament\Actions\ExportAction;
use App\Filament\Exports\CustomerExporter;

$table->headerActions([
  ExportAction::make()
    ->exporter(CustomerExporter::class),
]),
```

## Grouping actions

```php
use Filament\Actions\ActionGroup;

ActionGroup::make([
  ViewAction::make(),
  EditAction::make(),
  DeleteAction::make(),
])
  ->icon('heroicon-m-ellipsis-vertical')
  ->tooltip('Actions'),
```

### Group styles

```php
ActionGroup::make([/* ... */])
  ->dropdown(),   // default dropdown
  ->iconButton(), // icon button trigger
  ->button(),     // button trigger
```

## Rate limiting

```php
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\RateLimiter;

Action::make('delete')
  ->action(function () {
    if (RateLimiter::tooManyAttempts(
      $rateLimitKey = 'delete:' . auth()->id(),
      maxAttempts: 5,
    )) {
      Notification::make()
        ->title('Too many attempts')
        ->body('Please try again in ' . RateLimiter::availableIn($rateLimitKey) . ' seconds.')
        ->danger()
        ->send();
      return;
    }
    RateLimiter::hit($rateLimitKey);
    // perform action...
  }),
```

## Common pitfalls

- Forgetting `->requiresConfirmation()` on destructive actions
- Not handling authorization (UI hiding is not security)
- Import/export requires queue batch and notification table migrations
- Using `->form()` instead of `->schema()` in v5 (API was renamed)
- Using `->action()` closure without returning when you want to halt execution
