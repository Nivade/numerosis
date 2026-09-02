# Widgets

## Contents

- Introduction
- Creating widgets
- Widget types
- Stats overview widgets
- Chart widgets
- Table widgets
- Custom widgets
- Widget placement
- Filtering widget data
- Sorting and grid customization
- Conditional visibility
- Polling
- Global settings

## Introduction

Widgets are dashboard components that display data in specific formats: statistics, charts, tables, or custom content. They appear on the dashboard by default but can be placed on any page, including resource pages. Widget classes live in `app/Filament/Widgets`.

## Creating widgets

```bash
php artisan make:filament-widget MyWidget
```

This prompts for widget type:
- **Custom** — build from scratch with a Blade view
- **Chart** — chart.js powered chart
- **Stats overview** — statistics cards
- **Table** — embedded table

## Widget types

### Stats overview widgets

Display key metrics as stat cards:

```php
namespace App\Filament\Widgets;

use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class StatsOverview extends BaseWidget
{
  protected function getStats(): array
  {
    return [
      Stat::make('Unique views', '192.1k')
        ->description('32k increase')
        ->descriptionIcon('heroicon-m-arrow-trending-up')
        ->color('success')
        ->chart([7, 2, 10, 3, 15, 4, 17]),
      Stat::make('Bounce rate', '21%')
        ->description('7% increase')
        ->descriptionIcon('heroicon-m-arrow-trending-down')
        ->color('danger'),
      Stat::make('Average time on page', '3:12')
        ->description('3% increase')
        ->descriptionIcon('heroicon-m-arrow-trending-up')
        ->color('success'),
    ];
  }
}
```

#### Stat methods

| Method              | Purpose                                    |
|---------------------|--------------------------------------------|
| `->description()`   | Subtext below the value                    |
| `->descriptionIcon()` | Icon next to the description             |
| `->color()`         | `success`, `danger`, `warning`, `info`, `gray` |
| `->chart()`         | Mini sparkline chart (array of numbers)    |
| `->extraAttributes()` | Additional HTML attributes               |
| `->url()`           | Make the stat clickable                    |

### Chart widgets

```bash
php artisan make:filament-widget RevenueChart --chart
```

```php
namespace App\Filament\Widgets;

use Filament\Widgets\ChartWidget;

class RevenueChart extends ChartWidget
{
  protected static ?string $heading = 'Revenue';
  protected static ?string $maxHeight = '300px';

  protected function getData(): array
  {
    return [
      'datasets' => [
        [
          'label' => 'Revenue',
          'data' => [2000, 3000, 4500, 3200, 5100, 4800, 6200],
          'backgroundColor' => '#36A2EB',
          'borderColor' => '#36A2EB',
        ],
      ],
      'labels' => ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul'],
    ];
  }

  protected function getType(): string
  {
    return 'line'; // line, bar, pie, doughnut, radar, polarArea, bubble, scatter
  }
}
```

#### Chart configuration

```php
protected function getOptions(): array
{
  return [
    'scales' => [
      'y' => ['beginAtZero' => true],
    ],
    'plugins' => [
      'legend' => ['display' => true],
    ],
  ];
}
```

### Table widgets

Embed a full table inside a widget:

```php
namespace App\Filament\Widgets;

use App\Models\Order;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;

class LatestOrders extends BaseWidget
{
  protected static ?int $sort = 2;
  protected int | string | array $columnSpan = 'full';

  public function table(Table $table): Table
  {
    return $table
      ->query(Order::query()->latest()->limit(5))
      ->columns([
        TextColumn::make('id')->label('#'),
        TextColumn::make('customer.name'),
        TextColumn::make('total')->money('EUR'),
        TextColumn::make('status')->badge(),
        TextColumn::make('created_at')->dateTime(),
      ]);
  }
}
```

### Custom widgets

Custom widgets use a Blade view:

```php
namespace App\Filament\Widgets;

use Filament\Widgets\Widget;

class WelcomeWidget extends Widget
{
  protected static string $view = 'filament.widgets.welcome';
  protected static ?int $sort = -1;
  protected int | string | array $columnSpan = 'full';
}
```

## Widget placement

### Dashboard

Widgets appear on the dashboard by default. Disable default widgets:

```php
use Filament\Panel;

$panel->widgets([]);
```

### Resource pages

Register widgets on resource pages:

```php
// In your Resource class:
public static function getWidgets(): array
{
  return [
    Widgets\CustomerStatsOverview::class,
  ];
}
```

Then in the page class:

```php
// In ListCustomers page:
protected function getHeaderWidgets(): array
{
  return [
    Widgets\CustomerStatsOverview::class,
  ];
}

// Or footer widgets:
protected function getFooterWidgets(): array
{
  return [
    Widgets\LatestOrders::class,
  ];
}
```

## Filtering widget data

Dashboard-wide filters require a custom Dashboard page class.

### Filter form on the dashboard

Create a custom `app/Filament/Pages/Dashboard.php` extending the base Dashboard, add the `HasFiltersForm` trait, and define `filtersForm()`:

```php
use Filament\Forms\Components\DatePicker;
use Filament\Pages\Dashboard as BaseDashboard;
use Filament\Pages\Dashboard\Concerns\HasFiltersForm;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class Dashboard extends BaseDashboard
{
  use HasFiltersForm;

  public function filtersForm(Schema $schema): Schema
  {
    return $schema->components([
      Section::make()->schema([
        DatePicker::make('startDate'),
        DatePicker::make('endDate'),
      ])->columns(2),
    ]);
  }
}
```

Widgets that consume the filter data must use `InteractsWithPageFilters`:

```php
use Filament\Widgets\Concerns\InteractsWithPageFilters;

class StatsOverview extends StatsOverviewWidget
{
  use InteractsWithPageFilters;

  public function getStats(): array
  {
    $startDate = $this->pageFilters['startDate'] ?? null;
    $endDate   = $this->pageFilters['endDate'] ?? null;

    return [
      Stat::make('Total orders', Order::query()
        ->when($startDate, fn ($q) => $q->whereDate('created_at', '>=', $startDate))
        ->when($endDate, fn ($q) => $q->whereDate('created_at', '<=', $endDate))
        ->count()),
    ];
  }
}
```

### Filter modal on the dashboard

Use `HasFiltersAction` with `FilterAction` in `getHeaderActions()` for a modal-based filter:

```php
use Filament\Pages\Dashboard as BaseDashboard;
use Filament\Pages\Dashboard\Actions\FilterAction;
use Filament\Pages\Dashboard\Concerns\HasFiltersAction;
use Filament\Forms\Components\DatePicker;

class Dashboard extends BaseDashboard
{
  use HasFiltersAction;

  protected function getHeaderActions(): array
  {
    return [
      FilterAction::make()
        ->schema([
          DatePicker::make('startDate'),
          DatePicker::make('endDate'),
        ]),
    ];
  }
}
```

### Chart widget-specific filters

For filters internal to a `ChartWidget`, use `HasFiltersSchema` on the widget itself:

```php
use Filament\Widgets\ChartWidget\Concerns\HasFiltersSchema;
use Filament\Forms\Components\DatePicker;
use Filament\Schemas\Schema;

class RevenueChart extends ChartWidget
{
  use HasFiltersSchema;

  public function filtersSchema(Schema $schema): Schema
  {
    return $schema->components([
      DatePicker::make('startDate')->default(now()->subDays(30)),
      DatePicker::make('endDate')->default(now()),
    ]);
  }
}
```

### Persisting filters in session

```php
protected static bool $persistFiltersInSession = true;
```

## Sorting and grid customization

### Sort order

```php
protected static ?int $sort = 2;
```

### Widget column span

```php
protected int | string | array $columnSpan = 'full'; // or 1, 2, etc.
```

### Dashboard grid columns

Widget grid columns are configured per-page (not on the Panel). Override in your custom Dashboard page or any resource page:

```php
protected function getHeaderWidgetsColumns(): int | string | array
{
  return 3;
}

protected function getFooterWidgetsColumns(): int | string | array
{
  return 2;
}
```

### Responsive widget grid

```php
protected int | string | array $columnSpan = [
  'sm' => 1,
  'md' => 2,
  'xl' => 3,
];
```

## Conditional visibility

```php
public static function canView(): bool
{
  return auth()->user()->can('viewDashboard');
}
```

## Polling

Auto-refresh widget data at intervals:

```php
protected static ?string $pollingInterval = '10s';

// Disable polling:
protected static ?string $pollingInterval = null;
```

## Common pitfalls

- Forgetting `$columnSpan = 'full'` for table widgets that need full width
- Not registering widgets in `getWidgets()` on the resource before using them in pages
- Using heavy queries without caching in widgets — they refresh on every page load (or poll)
- Chart data format must match Chart.js schema exactly
- Not setting `$sort` causes unpredictable widget ordering
