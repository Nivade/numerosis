# Panel Configuration

## Contents

- Introduction
- Creating and configuring panels
- Path and domain
- Authentication
- Navigation
- Colors and theming
- Plugins
- Multi-tenancy
- SPA mode
- Middleware
- Lifecycle hooks
- Render hooks
- Error notifications
- Database transactions
- Broadcasting
- Assets
- Deployment and optimization

## Introduction

A Panel is the top-level shell of a Filament application. It defines the auth guard, navigation, colors, plugins, tenancy, and which resources/pages/widgets are registered. Panel providers live in `app/Providers/Filament`. The default panel is `AdminPanelProvider.php`.

## Creating and configuring panels

### Default admin panel

Created automatically by `filament:install`:

```php
use Filament\Panel;

public function panel(Panel $panel): Panel
{
  return $panel
    ->default()
    ->id('admin')
    ->path('admin')
    ->login()
    ->colors([
      'primary' => Color::Amber,
    ])
    ->discoverResources(
      in: app_path('Filament/Resources'),
      for: 'App\\Filament\\Resources',
    )
    ->discoverPages(
      in: app_path('Filament/Pages'),
      for: 'App\\Filament\\Pages',
    )
    ->discoverWidgets(
      in: app_path('Filament/Widgets'),
      for: 'App\\Filament\\Widgets',
    );
}
```

### Creating a new panel

```bash
php artisan make:filament-panel app
```

## Path and domain

```php
$panel->path('admin'),        // URL prefix
$panel->domain('admin.example.com'), // Restrict to domain
```

## Authentication

### Auth guard

```php
$panel->authGuard('web'),
```

### Login, registration, password reset

```php
$panel
  ->login()
  ->registration()
  ->passwordReset()
  ->emailVerification()
  ->profile(),
```

### Custom login page

```php
use App\Filament\Pages\Auth\Login;

$panel->login(Login::class),
```

### Panel access

Control who can access the panel:

The `User` model must implement the `FilamentUser` contract:

```php
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Foundation\Auth\User as Authenticatable;

class User extends Authenticatable implements FilamentUser
{
  public function canAccessPanel(Panel $panel): bool
  {
    return $this->hasRole('admin');
  }
}
```

You can use `$panel->getId()` to restrict logic to a specific panel.

## Navigation

### Navigation mode

```php
$panel->sidebarCollapsibleOnDesktop(),
$panel->sidebarFullyCollapsibleOnDesktop(),
$panel->topNavigation(),
```

### Navigation groups and items

```php
use Filament\Navigation\NavigationGroup;
use Filament\Navigation\NavigationItem;

$panel->navigationGroups([
  NavigationGroup::make('Shop')
    ->icon('heroicon-o-shopping-bag')
    ->collapsed(),
  NavigationGroup::make('Settings'),
]),

$panel->navigationItems([
  NavigationItem::make('Dashboard')
    ->url('/admin')
    ->icon('heroicon-o-home')
    ->isActiveWhen(fn () => request()->routeIs('filament.admin.pages.dashboard'))
    ->sort(0),
]),
```

### User menu

```php
use Filament\Navigation\MenuItem;

$panel->userMenuItems([
  MenuItem::make()
    ->label('Settings')
    ->url('/settings')
    ->icon('heroicon-o-cog-6-tooth'),
  'logout' => MenuItem::make()
    ->label('Sign out'),
]),
```

### Breadcrumbs

```php
$panel->breadcrumbs(false), // Disable breadcrumbs
```

## Colors and theming

### Setting colors

```php
use Filament\Support\Colors\Color;

$panel->colors([
  'primary' => Color::Indigo,
  'danger' => Color::Rose,
  'gray' => Color::Zinc,
  'info' => Color::Blue,
  'success' => Color::Emerald,
  'warning' => Color::Orange,
]),
```

Or with custom hex:

```php
$panel->colors([
  'primary' => '#6366f1',
]),
```

### Font

```php
$panel->font('Inter'),
```

### Dark mode

```php
$panel->darkMode(true),          // Enable (default)
$panel->darkMode(false),         // Disable
$panel->darkModeForced(),        // Force dark mode
```

### Custom theme

```bash
php artisan make:filament-theme
```

This creates a CSS file at `resources/css/filament/admin/theme.css` and a Tailwind config. Register in the panel:

```php
$panel->viteTheme('resources/css/filament/admin/theme.css'),
```

### Brand logo

```php
$panel
  ->brandLogo(asset('images/logo.svg'))
  ->darkModeBrandLogo(asset('images/logo-dark.svg'))
  ->brandLogoHeight('2rem')
  ->brandName('My App'),
```

### Favicon

```php
$panel->favicon(asset('images/favicon.ico')),
```

## Plugins

Register plugins in the panel:

```php
use Filament\SpatieLaravelTranslatablePlugin;

$panel->plugins([
  SpatieLaravelTranslatablePlugin::make()
    ->defaultLocales(['en', 'nl']),
]),
```

### Creating plugins

```bash
php artisan make:filament-plugin MyPlugin
```

## Multi-tenancy

### Setup

```php
use App\Models\Team;

$panel->tenant(Team::class),
```

### Tenant registration

```php
$panel
  ->tenant(Team::class)
  ->tenantRegistration(),
```

### Tenant menu items

```php
$panel->tenantMenuItems([
  MenuItem::make()
    ->label('Settings')
    ->url(fn () => Settings::getUrl())
    ->icon('heroicon-m-cog-8-tooth'),
]),
```

### Tenant middleware

```php
$panel->tenantMiddleware([
  ApplyTenantScopes::class,
], isPersistent: true),
```

### Tenant billing

```php
use Filament\Billing\Providers\SparkBillingProvider;

$panel
  ->tenant(Team::class)
  ->tenantBillingProvider(new SparkBillingProvider()),
```

## SPA mode

Enable single-page application navigation:

```php
$panel->spa(),
```

### SPA with prefetching

Prefetch pages on hover for even faster navigation:

```php
$panel->spa(hasPrefetching: true),
```

### Disabling SPA for specific URLs

```php
$panel->spa()
  ->spaUrlExceptions([
    '*/downloads/*',
  ]),
```

## Middleware

```php
$panel->middleware([
  EncryptCookies::class,
  AddQueuedCookiesToResponse::class,
  StartSession::class,
  AuthenticateSession::class,
  ShareErrorsFromSession::class,
  VerifyCsrfToken::class,
  SubstituteBindings::class,
]),

$panel->authMiddleware([
  Authenticate::class,
]),
```

## Lifecycle hooks

```php
$panel->bootUsing(function (Panel $panel) {
  // runs when panel boots
}),
```

## Render hooks

Inject content at specific points in the layout:

```php
use Filament\View\PanelsRenderHook;

$panel->renderHook(
  PanelsRenderHook::BODY_START,
  fn () => view('custom-banner'),
),
```

Common render hooks:
- `panels::body.start` / `panels::body.end`
- `panels::head.start` / `panels::head.end`
- `panels::sidebar.nav.start` / `panels::sidebar.nav.end`
- `panels::topbar.start` / `panels::topbar.end`
- `panels::content.start` / `panels::content.end`
- `panels::footer`

## Error notifications

```php
$panel->registerErrorNotification(
  title: 'An error occurred',
  body: 'Please try again later.',
),

$panel->hiddenErrorNotification(403),
$panel->disabledErrorNotification(503),
```

## Database transactions

```php
$panel->databaseTransactions(),
```

## Broadcasting

```php
$panel->broadcasting(false), // Disable broadcasting
```

## Assets

Register additional CSS/JS:

```php
use Filament\Support\Assets\Css;
use Filament\Support\Assets\Js;
use Filament\Support\Facades\FilamentAsset;

FilamentAsset::register([
  Css::make('custom-stylesheet', __DIR__ . '/../../resources/css/custom.css'),
  Js::make('custom-script', __DIR__ . '/../../resources/js/custom.js'),
]);
```

## Deployment and optimization

### Optimize for production

```bash
php artisan filament:optimize
```

This caches component and icon registrations.

### Clear optimization cache

```bash
php artisan filament:optimize-clear
```

### Full deployment checklist

1. `php artisan filament:optimize`
2. `php artisan optimize`
3. `php artisan view:cache`
4. `php artisan icons:cache`
5. Ensure panel access authorization is properly configured
6. Verify queue workers are running (if using import/export or database notifications)
7. Ensure assets are built and up-to-date (`npm run build`)

## Common pitfalls

- Panel not showing → check provider registration in `config/app.php` or auto-discovery
- Users can't access panel → ensure `canAccessPanel()` returns true or the user implements `FilamentUser`
- Missing navigation items → verify resource discovery paths match your directory structure
- Theme changes not applying → rebuild assets with `npm run build` and clear view cache
- Tenancy not scoping queries → verify tenant middleware is registered as persistent
- SPA mode breaking external links → use `spaUrlExceptions()` for those routes
