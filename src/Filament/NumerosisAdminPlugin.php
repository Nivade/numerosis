<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Filament;

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
use Nvade\Numerosis\Features\Ui\AdminPanelFeature;
use Nvade\Numerosis\Filament\Concerns\AppliesNumerosisPanelTheme;
use Nvade\Numerosis\Http\Middleware\Authenticate;
use Nvade\Numerosis\Support\Features;

/**
 * Everything the central admin panel needs that this package, not the host,
 * knows about: where its resources live, which guard it authenticates on,
 * which middleware stack it runs, which hostnames it answers on.
 *
 * Before this existed, a host copied all of it into its own
 * `AdminPanelProvider` — and so did this package's own Workbench harness, so
 * there were two hand-maintained copies of one panel definition and they had
 * already drifted: the harness copy was missing `->registration()`,
 * `->profile()`, `->persistentMiddleware(['universal'])`, `->domains()` and
 * the navigation groups, and the two resolved the package's source directory
 * two different ways. That is the same two-copies failure
 * `.claude/rules/auth-login.md` records for the passwordless-login
 * components, and the fix is the same shape: one definition, referenced
 * twice, rather than two definitions kept in agreement by hand.
 *
 * `->colors()` used to stay with the host for branding; it does not
 * anymore (design-system-unification Phase 4) — `->viteTheme()` plus
 * {@see AppliesNumerosisPanelTheme}
 * remap Filament's colour vars from resources/css/tokens.css instead, so a
 * host `->colors()` call after the plugin would fight
 * `FilamentColor::register()`'s per-container memoisation for nothing (Phase
 * 1 audit §1.7). What still stays with the host: the decision to register
 * the panel at all — a `Plugin` configures a panel, it cannot decide whether
 * one exists. {@see self::shouldRegisterPanel()} is
 * the gate a host's provider calls for that.
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

    /**
     * Whether a host should register this panel at all.
     *
     * Lives here rather than in the host's provider so that turning
     * `AdminPanelFeature` off actually removes the panel, instead of removing
     * it only in the hosts that remembered to check.
     */
    public static function shouldRegisterPanel(): bool
    {
        return Features::enabled(AdminPanelFeature::NAME);
    }

    public function register(Panel $panel): void
    {
        $panel = $this->applyNumerosisPanelTheme($panel);

        $panel
            ->id('admin')
            ->viteTheme('resources/css/filament-theme.css')
            ->path('admin')
            // Read from config rather than the literal 'web': the guard name
            // is host-configurable, and `.claude/rules/auth-guards.md` is
            // explicit that a panel omitting authGuard() rides
            // auth.defaults.guard, which moves mid-request once tenancy is
            // initialized.
            ->authGuard(Config::string('auth.defaults.guards.context.central'))
            ->login()
            ->registration()
            ->profile()
            ->discoverResources(in: $this->path('Resources'), for: 'Nvade\\Numerosis\\Filament\\Admin\\Resources')
            ->discoverClusters(in: $this->path('Clusters'), for: 'Nvade\\Numerosis\\Filament\\Admin\\Clusters')
            ->discoverPages(in: $this->path('Pages'), for: 'Nvade\\Numerosis\\Filament\\Admin\\Pages')
            ->pages([
                Dashboard::class,
            ])
            ->discoverWidgets(in: $this->path('Widgets'), for: 'Nvade\\Numerosis\\Filament\\Admin\\Widgets')
            // BillingStatsWidget/RevenueChartWidget deliberately not
            // registered here: they already appear on the Billing cluster's
            // own Overview page (BillingDashboard), which also carries
            // SubscriptionsByPlanChart and RecentSubscriptionsTable that
            // never showed up here. Registering both here duplicated two of
            // the four widgets on the default Dashboard for no reason.
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
            // The 'universal' group is registered by
            // NumerosisServiceProvider::registerMiddleware(); persisting it
            // is what lets a route resolve on either a central or a tenant
            // host.
            ->persistentMiddleware(['universal'])
            ->domains($this->centralDomains())
            ->authMiddleware([
                Authenticate::class,
            ])
            ->navigationGroups([
                NavigationGroup::make()
                    ->label('Customers')
                    ->collapsible(false),
            ]);
    }

    public function boot(Panel $panel): void
    {
        //
    }

    /**
     * Filament's directory discovery needs a real filesystem path, and after
     * installation these files live under `vendor/`. `__DIR__` is the only
     * form that is right in every consumer *and* in this package's own
     * harness — `InstalledVersions::getInstallPath('nvade/numerosis')` is
     * wrong when the package is the root project, and a `dirname(__DIR__, N)`
     * count is wrong the moment a file moves.
     */
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
