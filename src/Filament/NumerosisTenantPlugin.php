<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Filament;

use AlizHarb\ActivityLog\ActivityLogPlugin;
use Filament\Actions\Action;
use Filament\Contracts\Plugin;
use Filament\Facades\Filament;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\View\PanelsRenderHook;
use Filament\Widgets\AccountWidget;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Config;
use Illuminate\View\Middleware\ShareErrorsFromSession;
use Nvade\Numerosis\Actions\Queries\GetAuthenticatedUser;
use Nvade\Numerosis\Concerns\InteractsWithTenantModules;
use Nvade\Numerosis\Contracts\Tenancy\ModulePlugin;
use Nvade\Numerosis\Enums\Tenancy\IdentificationMode;
use Nvade\Numerosis\Features\Modules\ModuleSystemFeature;
use Nvade\Numerosis\Features\Observability\ActivityLogFeature;
use Nvade\Numerosis\Features\Ui\TenantPanelFeature;
use Nvade\Numerosis\Filament\Concerns\AppliesNumerosisPanelTheme;
use Nvade\Numerosis\Filament\TenantAdmin\Clusters\Profile\Pages\General;
use Nvade\Numerosis\Filament\TenantAdmin\Clusters\Team\TeamCluster;
use Nvade\Numerosis\Filament\TenantAdmin\Pages\Billing;
use Nvade\Numerosis\Http\Middleware\ApplyDefaultBranding;
use Nvade\Numerosis\Http\Middleware\Authenticate;
use Nvade\Numerosis\Http\Middleware\EnsureSessionMatchesTenant;
use Nvade\Numerosis\Http\Middleware\EnsureTenantSubscriptionActive;
use Nvade\Numerosis\Http\Middleware\UpdateUserLastSeenMiddleware;
use Nvade\Numerosis\Livewire\Auth\PasswordlessLogin;
use Nvade\Numerosis\Models\Central\Tenant;
use Nvade\Numerosis\NumerosisServiceProvider;
use Nvade\Numerosis\Providers\TenancyServiceProvider;
use Nvade\Numerosis\Support\Features;
use Nvade\Numerosis\Support\Numerosis;

/**
 * The complete tenant panel: its resources, tenant-domain routing, guard and
 * middleware stack.
 *
 * Compose it into a panel provider of your own to add resources or pages.
 * Colours come from the theme rather than `->colors()`; override them through
 * the custom properties in `tokens.css`.
 *
 * If you replace {@see self::middleware()}, keep its order — see that
 * method. Call {@see self::shouldRegisterPanel()} to decide whether to
 * register the panel for the current request at all.
 */
class NumerosisTenantPlugin implements Plugin
{
    use AppliesNumerosisPanelTheme;
    use InteractsWithTenantModules;

    public static function make(): static
    {
        return resolve(static::class);
    }

    public function getId(): string
    {
        return 'numerosis-tenant';
    }

    /**
     * Whether to register this panel for the current request.
     *
     * Never on a central-domain request: the panel's `{tenant}.<domain>`
     * pattern matches the central host too, so registering it there lets its
     * `/` route win over the central app's homepage. Console commands are
     * exempt, so `route:list`, queue workers and tests still see the panel.
     *
     * Worth knowing: no tenant-panel route exists during a central-domain
     * request, so links into a tenant panel from central pages have to be
     * built from the tenant's own URL rather than by route name.
     */
    public static function shouldRegisterPanel(): bool
    {
        if (! Features::enabled(TenantPanelFeature::NAME)) {
            return false;
        }

        // Path mode's tenant routes deliberately live on the central domain
        // (path-prefixed) — the central-domain skip below exists to stop a
        // domain-based tenant pattern winning over the central app's own
        // routes, which cannot happen when there is no separate pattern to
        // win with. Registering unconditionally is what makes the panel
        // reachable outside a console process at all under this mode.
        if (IdentificationMode::current() === IdentificationMode::Path) {
            return true;
        }

        if (! Numerosis::isCentralDomain()) {
            return true;
        }

        return app()->runningInConsole();
    }

    public function register(Panel $panel): void
    {
        $panel = $this->applyNumerosisPanelTheme($panel);

        $panel
            ->id('tenantAdmin')
            ->theme(NumerosisServiceProvider::THEME_ID)
            ->tenantDomain(static::tenantDomainPattern())
            ->tenant(Numerosis::model(Tenant::class), 'id')
            ->path('/')
            ->spa()
            ->login(PasswordlessLogin::class)
            ->discoverResources(in: $this->path('Resources'), for: 'Nvade\\Numerosis\\Filament\\TenantAdmin\\Resources')
            ->discoverPages(in: $this->path('Pages'), for: 'Nvade\\Numerosis\\Filament\\TenantAdmin\\Pages')
            ->discoverClusters(in: $this->path('Clusters'), for: 'Nvade\\Numerosis\\Filament\\TenantAdmin\\Clusters')
            ->pages([
                Dashboard::class,
                Billing::class,
            ])
            ->userMenuItems([
                'profile' => Action::make('profile')
                    ->label('My Profile')
                    ->icon('heroicon-o-user-circle')
                    ->url(fn () => General::getUrl(['record' => GetAuthenticatedUser::run()])),
                Action::make('billing')
                    ->label('Billing & Subscription')
                    ->icon('heroicon-o-credit-card')
                    ->url(fn () => Billing::getUrl()),
            ])
            ->discoverWidgets(in: $this->path('Widgets'), for: 'Nvade\\Numerosis\\Filament\\TenantAdmin\\Widgets')
            ->widgets([
                AccountWidget::class,
            ])
            ->middleware($this->middleware())
            // Pinned, never inherited: `auth.defaults.guard` moves during a
            // request once tenancy initializes, so a panel that omits this
            // authenticates against whatever ran last.
            ->authGuard(Config::string('numerosis.auth.guards.tenant'))
            ->authMiddleware([
                Authenticate::class,
                EnsureTenantSubscriptionActive::class,
            ])
            ->plugins($this->plugins())
            ->renderHook(
                PanelsRenderHook::CONTENT_START,
                fn (): string => $this->paymentStatusBanner(),
            );
    }

    /**
     * `null` (path mode) makes Filament fall back to its own path-based
     * tenant routing — `{tenant}` prefixed under the panel's own path,
     * resolved via `getTenant()`'s default `resolveRouteBinding($key, 'id')`
     * with no domain concept at all. `'{tenant}'` (custom domain mode) makes
     * `Route::domain('{tenant}')` match the entire host, dots included —
     * `Filament\Panel::register()` widens the `tenant` route-parameter
     * pattern to allow them specifically for a bare `{tenant}`/`{tenant:*}`
     * value. `Tenant::resolveRouteBinding()` is what turns that raw host
     * string into the right tenant for this mode; see its own docblock.
     */
    protected static function tenantDomainPattern(): ?string
    {
        return match (IdentificationMode::current()) {
            IdentificationMode::Subdomain => Config::string('numerosis.domains.tenant_pattern'),
            IdentificationMode::CustomDomain => '{tenant}',
            IdentificationMode::Path => null,
        };
    }

    /** The banner shown above panel content when payment needs attention. */
    protected function paymentStatusBanner(): string
    {
        /** @var view-string $view */
        $view = 'numerosis::components.billing.payment-status-banner';

        $tenant = Filament::getTenant();

        return view($view, [
            'subscription' => $tenant instanceof Tenant ? $tenant->latestSubscription() : null,
        ])->render();
    }

    public function boot(Panel $panel): void
    {
        //
    }

    /**
     * The tenant panel's middleware stack. Its order is load-bearing and
     * nothing validates it — tenant identification must run first, and
     * `EnsureSessionMatchesTenant` must follow `StartSession`. Reordering
     * either produces a cross-tenant identity leak rather than an error.
     *
     * @return list<class-string|string>
     */
    protected function middleware(): array
    {
        return [
            TenancyServiceProvider::identificationMiddleware(),
            // Second gate behind shouldRegisterPanel(). Keep it: without it,
            // a central-domain request reaching these routes runs on past
            // identification with no tenant, and later middleware that
            // assumes one throws rather than 404ing. A no-op under
            // IdentificationMode::Path, where tenant routes deliberately
            // live on the central domain — see
            // TenancyServiceProvider::tenancyRouteMiddleware().
            TenancyServiceProvider::tenancyRouteMiddleware(),
            EncryptCookies::class,
            AddQueuedCookiesToResponse::class,
            StartSession::class,
            // One session spans every tenant subdomain, and the session
            // stores only a user id — which means a different person on the
            // next tenant. This forgets it when the tenant changes.
            EnsureSessionMatchesTenant::class,
            AuthenticateSession::class,
            ShareErrorsFromSession::class,
            PreventRequestForgery::class,
            UpdateUserLastSeenMiddleware::class,
            SubstituteBindings::class,
            DisableBladeIconComponents::class,
            DispatchServingFilamentEvent::class,
            ApplyDefaultBranding::class,
        ];
    }

    /**
     * @return list<Plugin>
     */
    protected function plugins(): array
    {
        $plugins = [];

        if (Features::enabled(ActivityLogFeature::NAME) && class_exists(ActivityLogPlugin::class)) {
            $plugins[] = ActivityLogPlugin::make()
                ->navigationGroup('People')
                ->cluster(TeamCluster::class);
        }

        return [...$plugins, ...$this->enabledModulePlugins()];
    }

    /**
     * Filament plugins belonging to modules the current tenant has enabled,
     * from the slug => plugin-class map in `numerosis.modules.plugins`.
     *
     * @return list<ModulePlugin>
     */
    protected function enabledModulePlugins(): array
    {
        if (! Features::enabled(ModuleSystemFeature::NAME)) {
            return [];
        }

        $plugins = [];

        /** @var array<string, class-string<ModulePlugin>> $map */
        $map = Config::array('numerosis.modules.plugins', []);

        foreach ($map as $slug => $pluginClass) {
            if ($this->isModuleEnabled($slug)) {
                $plugins[] = $pluginClass::make();
            }
        }

        return $plugins;
    }

    /** Absolute path to one of this panel's discovery directories. */
    protected function path(string $suffix): string
    {
        return __DIR__.'/TenantAdmin/'.$suffix;
    }
}
