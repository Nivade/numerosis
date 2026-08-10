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
use Stancl\Tenancy\Middleware\PreventAccessFromCentralDomains;

/**
 * The tenant panel's whole definition, in the package that owns every piece
 * of it. See {@see NumerosisAdminPlugin} for why this is a plugin rather than
 * something each host copies.
 *
 * The tenant side is the one where copying was most dangerous, because the
 * middleware **order** here is load-bearing and nothing checks it:
 * identification must run first, `EnsureSessionMatchesTenant` must come after
 * `StartSession` (`.claude/rules/tenant-caching.md` — without it, one tenant's
 * session id authenticates a different person on the next tenant), and
 * `authGuard('tenant')` must be pinned because the ambient default guard
 * moves mid-request (`.claude/rules/auth-guards.md`). A host reordering that
 * list while copying it produces a cross-tenant identity leak, not an error.
 *
 * `->colors()` used to stay with the host for branding; it does not anymore
 * (design-system-unification Phase 4) — `->theme()` plus
 * {@see AppliesNumerosisPanelTheme} remap
 * Filament's colour vars from resources/css/tokens.css instead, sidestepping
 * `FilamentColor::register()`'s per-container memoisation (Phase 1 audit
 * §1.7) rather than fighting it. The optional `nvade/branding` app-module's
 * own per-tenant override is a separate mechanism (its `ApplyBranding`
 * middleware, thin-app-owned) and is untouched by this. What stays with the
 * host: the decision to register the panel at all.
 * {@see self::shouldRegisterPanel()}.
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
     * Whether a host should register this panel for the current request.
     *
     * Two conditions, and the second is not an optimisation. This panel's
     * `{tenant}.<domain>` pattern also matches the central host itself — the
     * central subdomain fits `{tenant}` just as well as a real tenant id — so
     * registering it on a central-domain request lets its `/` route steal the
     * match from the central app's own `/` (`home`) route. That was tried and
     * produced a 404 on the central domain instead of the homepage. Gating
     * registration is what avoids the ambiguity rather than resolving it.
     *
     * Consequence worth knowing: no `filament.tenantAdmin.*` route exists
     * during a central-domain request, which is why
     * `Http\Controllers\Socialite\Login` builds its tenant-dashboard link off
     * `route('home')` — see its own `tenantDashboardUrl()` docblock.
     *
     * `runningInConsole()` keeps the panel registered for route:list,
     * queue workers and tests, none of which have a meaningful Host header.
     */
    public static function shouldRegisterPanel(): bool
    {
        if (! Features::enabled(TenantPanelFeature::NAME)) {
            return false;
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
            ->tenantDomain(Config::string('numerosis.domains.tenant_pattern'))
            ->tenant(Numerosis::model(Tenant::class), 'id')
            ->path('/')
            ->spa()
            ->login(PasswordlessLogin::class)
            ->discoverResources(in: $this->path('Resources'), for: 'Nvade\\Numerosis\\Filament\\TenantAdmin\\Resources')
            ->discoverPages(in: $this->path('Pages'), for: 'Nvade\\Numerosis\\Filament\\TenantAdmin\\Pages')
            ->discoverClusters(in: $this->path('Clusters'), for: 'Nvade\\Numerosis\\Filament\\TenantAdmin\\Clusters')
            ->pages([
                // ModulesMarketplace is not listed: it sits inside
                // discoverPages()'s scan (Pages/Modules/Marketplace.php), so
                // an explicit entry would be redundant and, if made
                // conditional, misleading — discovery registers it regardless
                // of array membership. The real gate is its own canAccess()
                // and shouldRegisterNavigation(), both reading
                // Features::enabled(ModuleSystemFeature::NAME).
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
            ->authGuard(Config::string('auth.defaults.guards.context.tenant'))
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
     * Extracted so the view name can carry a `view-string` annotation —
     * `view()` is typed `view-string|null` at level 9 and a bare literal
     * inside a closure argument has nowhere to hang one.
     */
    protected function paymentStatusBanner(): string
    {
        /** @var view-string $view */
        $view = 'numerosis::components.billing.payment-status-banner';

        $tenant = Filament::getTenant();

        return view($view, [
            // Called as a method, not read as a property:
            // Billable::latestSubscription() returns ?Subscription directly
            // rather than a Relation, and Eloquent's magic property access
            // always throws for a method that is not one — the
            // @property-read annotation on Tenant is a lie.
            'subscription' => $tenant instanceof Tenant ? $tenant->latestSubscription() : null,
        ])->render();
    }

    public function boot(Panel $panel): void
    {
        //
    }

    /**
     * Order matters here more than anywhere else in this package; see the
     * class docblock.
     *
     * @return list<class-string|string>
     */
    protected function middleware(): array
    {
        return [
            TenancyServiceProvider::TENANCY_IDENTIFICATION,
            // Belt-and-braces: shouldRegisterPanel() already stops these
            // routes existing for a central-domain request. Kept in case a
            // future change to that gate lets the panel register centrally
            // again — without it a request sails past identification with no
            // tenant found, and later middleware that assumes tenancy is
            // initialized (UpdateUserLastSeenMiddleware) throws instead of
            // 404ing.
            PreventAccessFromCentralDomains::class,
            EncryptCookies::class,
            AddQueuedCookiesToResponse::class,
            StartSession::class,
            // Must follow StartSession: it forgets the tenant guard's session
            // key when the session's recorded tenant changes. One session
            // spans every tenant subdomain (SESSION_DOMAIN carries a leading
            // dot) and SessionGuard stores nothing but a primary key, so
            // without this the id written on tenant A authenticates whoever
            // holds that id on tenant B. See .claude/rules/tenant-caching.md.
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
     * A module's Filament plugin, registered only when the current tenant has
     * that module enabled, driven by the slug => plugin-class map in
     * `config('numerosis.modules.plugins')`. Core holds no reference to any
     * concrete module. {@see InteractsWithTenantModules} does not apply
     * per-plugin — see its own docblock.
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

    /** @see NumerosisAdminPlugin::path() — same reasoning. */
    protected function path(string $suffix): string
    {
        return __DIR__.'/TenantAdmin/'.$suffix;
    }
}
