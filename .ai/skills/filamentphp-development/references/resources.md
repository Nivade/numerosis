# Resources

## Contents

- Introduction
- Creating a resource
- Default file structure (v5)
- Simple (modal) resources
- Resource pages
- Resource forms
- Resource tables
- Soft deletes
- View pages and infolists
- Record titles
- Navigation
- URLs
- Authorization and policies
- Eloquent query customization
- Resource sub-navigation
- Common pitfalls

## Introduction

Resources are static classes that build CRUD interfaces for Eloquent models. They describe how administrators interact with data using tables and forms. Resources live in `app/Filament/Resources`.

## Creating a resource

```bash
php artisan make:filament-resource Customer
```

This generates the following directory structure:

```
app/Filament/Resources/
├── CustomerResource.php
└── CustomerResource/
    ├── Pages/
    │   ├── ListCustomers.php
    │   ├── CreateCustomer.php
    │   └── EditCustomer.php
    ├── Schemas/
    │   └── CustomerForm.php
    └── Tables/
        └── CustomersTable.php
```

> **Note**: This is the default v5 structure. Forms and tables are separated into their own classes. See the [Default file structure (v5)](#default-file-structure-v5) section below.

### Simple (modal) resources

For lightweight models where modal-based CRUD is sufficient:

```bash
php artisan make:filament-resource Customer --simple
```

Generates a single "Manage" page with modal create/edit/delete. No relation managers (they require Edit/View pages).

### Auto-generating forms and tables

```bash
php artisan make:filament-resource Customer --generate
```

Introspects the database to scaffold columns and fields automatically.

### Generate model, migration, and factory together

```bash
php artisan make:filament-resource Customer --generate --model --migration --factory
```

## Default file structure (v5)

In Filament v5, the form and table definitions are extracted into dedicated classes by default.

### Form schema class

`app/Filament/Resources/CustomerResource/Schemas/CustomerForm.php`:

```php
namespace App\Filament\Resources\CustomerResource\Schemas;

use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\DatePicker;
use Filament\Schemas\Schema;

class CustomerForm
{
  public static function configure(Schema $schema): Schema
  {
    return $schema
      ->components([
        TextInput::make('name')
          ->required()
          ->maxLength(255),
        TextInput::make('email')
          ->email()
          ->required()
          ->unique(ignoreRecord: true),
        DatePicker::make('date_of_birth'),
      ]);
  }
}
```

The resource class delegates to it:

```php
use App\Filament\Resources\CustomerResource\Schemas\CustomerForm;
use Filament\Schemas\Schema;

public static function form(Schema $schema): Schema
{
  return CustomerForm::configure($schema);
}
```

### Table class

`app/Filament/Resources/CustomerResource/Tables/CustomersTable.php`:

```php
namespace App\Filament\Resources\CustomerResource\Tables;

use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class CustomersTable
{
  public static function configure(Table $table): Table
  {
    return $table
      ->columns([
        TextColumn::make('name')->searchable()->sortable(),
        TextColumn::make('email')->searchable(),
        TextColumn::make('created_at')->dateTime()->sortable(),
      ]);
  }
}
```

The resource class delegates to it:

```php
use App\Filament\Resources\CustomerResource\Tables\CustomersTable;
use Filament\Tables\Table;

public static function table(Table $table): Table
{
  return CustomersTable::configure($table);
}
```

### Embedding inline (disabling split files)

To generate the form/infolist schema and table inline in the resource class (v3/v4 style), use:

```bash
php artisan make:filament-resource Customer --embed-schemas --embed-table
```

Or configure globally in `config/filament.php`:

```php
use Filament\Support\Enums\FileGenerationFlag;

'file_generation' => [
  'flags' => [
    FileGenerationFlag::EMBEDDED_PANEL_RESOURCE_SCHEMAS,
    FileGenerationFlag::EMBEDDED_PANEL_RESOURCE_TABLES,
  ],
],
```

---

## Resource pages

A standard resource has three pages: **List**, **Create**, and **Edit**. Optionally add a **View** page:

```bash
php artisan make:filament-resource Customer --view
```

### Custom resource pages

Additional pages can be registered in `getPages()`:

```php
public static function getPages(): array
{
  return [
    'index' => Pages\ListCustomers::route('/'),
    'create' => Pages\CreateCustomer::route('/create'),
    'edit' => Pages\EditCustomer::route('/{record}/edit'),
    'view' => Pages\ViewCustomer::route('/{record}'),
    'settings' => Pages\CustomerSettings::route('/{record}/settings'),
  ];
}
```

### Deleting resource pages

Remove the page class and its entry in `getPages()`. For example, removing the create page disables creation entirely.

## Resource forms

In the default v5 structure, form definitions live in `Schemas/CustomerForm.php` (see [Default file structure](#default-file-structure-v5)). The schema is defined in `configure()` on the form class, which is the same method signature whether inline or split.

The form class `configure()` method:

```php
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\DatePicker;
use Filament\Schemas\Schema;

public static function configure(Schema $schema): Schema
{
  return $schema
    ->components([
      TextInput::make('name')
        ->required()
        ->maxLength(255),
      TextInput::make('email')
        ->email()
        ->required()
        ->unique(ignoreRecord: true),
      DatePicker::make('date_of_birth'),
    ]);
}
```

### Hiding components based on operation

Use `visibleOn()` or `hiddenOn()` to show/hide fields on create vs edit:

```php
TextInput::make('password')
  ->password()
  ->required()
  ->visibleOn('create'),
```

## Resource tables

In the default v5 structure, table definitions live in `Tables/CustomersTable.php` (see [Default file structure](#default-file-structure-v5)). The table is defined in `configure()` on the table class.

The table class `configure()` method:

```php
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Actions\EditAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
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
        ->sortable(),
    ])
    ->filters([
      Filter::make('verified')
        ->query(fn (Builder $query) => $query->whereNotNull('email_verified_at')),
    ])
    ->recordActions([
      EditAction::make(),
    ])
    ->toolbarActions([
      BulkActionGroup::make([
        DeleteBulkAction::make(),
      ]),
    ]);
}
```

## Soft deletes

When using soft deletes, scaffold with:

```bash
php artisan make:filament-resource Customer --soft-deletes
```

This adds `TrashedFilter`, `DeleteAction`, `ForceDeleteAction`, `RestoreAction`, `ForceDeleteBulkAction`, and `RestoreBulkAction`.

Ensure your resource query includes trashed records:

```php
public static function getEloquentQuery(): Builder
{
  return parent::getEloquentQuery()
    ->withTrashed();
}
```

## Record titles

Customize the title attribute used for global search and breadcrumbs:

```php
protected static ?string $recordTitleAttribute = 'name';
```

## Navigation

### Setting a navigation icon

```php
protected static ?string $navigationIcon = 'heroicon-o-users';
```

### Sorting and grouping

```php
protected static ?int $navigationSort = 2;
protected static ?string $navigationGroup = 'Shop';
```

### Grouping under other items

```php
protected static ?string $navigationParentItem = 'Products';
```

### Custom model labels

```php
protected static ?string $modelLabel = 'customer';
protected static ?string $pluralModelLabel = 'customers';
```

## URLs

### Generating URLs to resource pages

```php
CustomerResource::getUrl(); // list
CustomerResource::getUrl('create');
CustomerResource::getUrl('edit', ['record' => $customer]);
CustomerResource::getUrl('view', ['record' => $customer]);
```

### Generating URLs to resource modals

```php
CustomerResource::getUrl(modals: ['edit' => $customer]);
```

## Authorization and policies

Filament integrates with Laravel policies automatically. Define a policy for the model and Filament respects these methods:

| Policy method   | Effect                                                                 |
|-----------------|------------------------------------------------------------------------|
| `viewAny()`     | Hides resource from navigation, prevents access to all pages           |
| `create()`      | Controls record creation                                               |
| `update()`      | Controls record editing                                                |
| `view()`        | Controls viewing a record                                              |
| `delete()`      | Prevents single record deletion                                        |
| `deleteAny()`   | Prevents bulk deletion                                                 |
| `restore()`     | Prevents single soft-deleted record restoration                        |
| `restoreAny()`  | Prevents bulk restoration                                              |
| `forceDelete()` | Prevents single force deletion                                         |
| `forceDeleteAny()` | Prevents bulk force deletion                                        |
| `reorder()`     | Controls record reordering                                             |

### Authorizing individual records in bulk actions

```php
DeleteBulkAction::make()
  ->authorizeIndividualRecords(),
```

### Skipping authorization

```php
protected static bool $shouldSkipAuthorization = true;
```

## Eloquent query customization

Override the base query for the resource:

```php
public static function getEloquentQuery(): Builder
{
  return parent::getEloquentQuery()->where('is_active', true);
}
```

### Disabling global scopes

```php
public static function getEloquentQuery(): Builder
{
  return parent::getEloquentQuery()
    ->withoutGlobalScopes([SoftDeletingScope::class]);
}
```

## Resource sub-navigation

Resources support sub-navigation between pages:

```php
public static function getRecordSubNavigation(Page $page): array
{
  return $page->generateNavigationItems([
    Pages\EditCustomer::class,
    Pages\ViewCustomer::class,
    Pages\CustomerSettings::class,
  ]);
}
```

## Common pitfalls

- Resource not appearing in navigation → check `viewAny()` policy
- Forms not saving → verify field names match database columns
- Relationships not loading → ensure Eloquent relationships are defined on the model
- Simple resources cannot have relation managers (they lack Edit/View pages)
