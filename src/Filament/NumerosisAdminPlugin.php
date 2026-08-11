<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Filament;

use AlizHarb\ActivityLog\ActivityLogPlugin;
use Filament\Contracts\Plugin;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\NavigationGroup;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\Widgets\AccountWidget;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Config;
use Illuminate\View\Middleware\ShareErrorsFromSession;
use Nvade\Numerosis\Features\Observability\ActivityLogFeature;
use Nvade\Numerosis\Features\Ui\AdminPanelFeature;
use Nvade\Numerosis\Filament\Concerns\AppliesNumerosisPanelTheme;
use Nvade\Numerosis\Http\Middleware\Authenticate;
use Nvade\Numerosis\NumerosisServiceProvider;
use Nvade\Numerosis\Support\Features;

/**
 * The complete central admin panel: its resources, guard, middleware stack
 * and the hostnames it answers on.
 *
 * Compose it into a panel provider of your own to add resources or pages
 * alongside it. Colours come from the theme rather than `->colors()`;
 * override them through the custom properties in `tokens.css`.
 *
 * A plugin cannot decide whether a panel exists at all — call
 * {@see self::shouldRegisterPanel()} for that.
 */
class NumerosisAdminPlugin implements Plugin
{
    use AppliesNumerosisPanelTheme;

    public static function make(): static
    {
        return resolve(static::class);
    }

    public function getId(): string
    {
        return 'numerosis-admin';
    }

    /** Whether this panel should be registered at all. */
    public static function shouldRegisterPanel(): bool
    {
        return Features::enabled(AdminPanelFeature::NAME);
    }

    public function register(Panel $panel): void
    {
        $panel = $this->applyNumerosisPanelTheme($panel);

        $panel
            ->id('admin')
            ->theme(NumerosisServiceProvider::THEME_ID)
            ->path('admin')
            // Pinned, never inherited: the default guard moves mid-request
            // once tenancy initializes.
            ->authGuard(Config::string('numerosis.auth.guards.central'))
            ->login()
            ->registration()
            ->profile()
            ->spa()
            ->databaseNotifications()
            ->databaseNotificationsPolling('30s')
            ->discoverResources(in: $this->path('Resources'), for: 'Nvade\\Numerosis\\Filament\\Admin\\Resources')
            ->discoverClusters(in: $this->path('Clusters'), for: 'Nvade\\Numerosis\\Filament\\Admin\\Clusters')
            ->discoverPages(in: $this->path('Pages'), for: 'Nvade\\Numerosis\\Filament\\Admin\\Pages')
            ->pages([
                Dashboard::class,
            ])
            ->discoverWidgets(in: $this->path('Widgets'), for: 'Nvade\\Numerosis\\Filament\\Admin\\Widgets')
            // The billing widgets live on the Billing cluster's own overview
            // page, not on the default dashboard.
            ->widgets([
                AccountWidget::class,
            ])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                PreventRequestForgery::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            // Lets a route resolve on either a central or a tenant host.
            ->persistentMiddleware(['universal'])
            ->domains($this->centralDomains())
            ->authMiddleware([
                Authenticate::class,
            ])
            ->navigationGroups([
                NavigationGroup::make()
                    ->label('Customers')
                    ->collapsible(false),
                // Roles/Permissions/Users resources declare this group by
                // name (getNavigationGroup()); registering it here just
                // gives it the same un-collapsible treatment as Customers
                // instead of Filament's unconfigured default.
                NavigationGroup::make()
                    ->label('Access Control')
                    ->collapsible(false),
            ])
            ->plugins($this->plugins());
    }

    /**
     * @return list<Plugin>
     */
    protected function plugins(): array
    {
        if (Features::enabled(ActivityLogFeature::NAME) && class_exists(ActivityLogPlugin::class)) {
            return [ActivityLogPlugin::make()];
        }

        return [];
    }

    public function boot(Panel $panel): void
    {
        //
    }

    /** Absolute path to one of this panel's discovery directories. */
    protected function path(string $suffix): string
    {
        return __DIR__.'/Admin/'.$suffix;
    }

    /**
     * @return list<string>
     */
    protected function centralDomains(): array
    {
        return array_values(array_filter(
            Config::array('tenancy.central_domains'),
            is_string(...),
        ));
    }
}
