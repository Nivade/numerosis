<?php

declare(strict_types=1);

namespace Workbench\App\Providers\Filament;

use AlizHarb\ActivityLog\ActivityLogPlugin;
use App\Models\Central\Tenant;
use Filament\Facades\Filament;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Widgets\AccountWidget;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Config;
use Illuminate\View\Middleware\ShareErrorsFromSession;
use Nvade\Numerosis\Features\Observability\ActivityLogFeature;
use Nvade\Numerosis\Features\Ui\TenantPanelFeature;
use Nvade\Numerosis\Filament\TenantAdmin\Clusters\Team\TeamCluster;
use Nvade\Numerosis\Filament\TenantAdmin\Pages\Billing;
use Nvade\Numerosis\Http\Middleware\Authenticate;
use Nvade\Numerosis\Http\Middleware\EnsureSessionMatchesTenant;
use Nvade\Numerosis\Http\Middleware\EnsureTenantSubscriptionActive;
use Nvade\Numerosis\Http\Middleware\UpdateUserLastSeenMiddleware;
use Nvade\Numerosis\Livewire\Auth\PasswordlessLogin;
use Nvade\Numerosis\Providers\TenancyServiceProvider;
use Nvade\Numerosis\Support\Features;
use Stancl\Tenancy\Middleware\PreventAccessFromCentralDomains;

/**
 * Minimal stand-in for the panel provider thin-app will own (Phase 7/8) —
 * only what package's own Filament resource/page tests need to exercise a
 * registered 'tenantAdmin' panel. No branding/module plugins here: those are
 * applied by their own plugins (see ApplyDefaultBranding's docblock),
 * registered by a real host, not by core's test harness.
 */
class TenantAdminPanelProvider extends PanelProvider
{
    public function register(): void
    {
        if (! Features::enabled(TenantPanelFeature::NAME)) {
            return;
        }

        if (! $this->isCentralDomainRequest() || app()->runningInConsole()) {
            Filament::registerPanel(
                fn (): Panel => $this->panel(Panel::make()),
            );
        }
    }

    /**
     * Inlined rather than `request()->isCentralDomain()` — that macro is
     * registered by thin-app's own `AppServiceProvider`, not by the
     * package, since `TenantAdminPanelProvider` itself is thin-app-owned
     * (see this class's docblock). Same logic the macro uses.
     */
    protected function isCentralDomainRequest(): bool
    {
        return in_array(app(Request::class)->getHost(), Config::array('tenancy.central_domains'), true);
    }

    public function panel(Panel $panel): Panel
    {
        $root = dirname(__DIR__, 4).'/src/Filament/TenantAdmin';

        return $panel
            ->id('tenantAdmin')
            ->colors([
                'primary' => Color::Rose,
            ])
            ->tenantDomain(Config::string('numerosis.domains.tenant_pattern'))
            ->tenant(Tenant::class, 'id')
            ->path('/')
            ->login(PasswordlessLogin::class)
            ->discoverResources(in: $root.'/Resources', for: 'Nvade\\Numerosis\\Filament\\TenantAdmin\\Resources')
            ->discoverPages(in: $root.'/Pages', for: 'Nvade\\Numerosis\\Filament\\TenantAdmin\\Pages')
            ->discoverClusters(in: $root.'/Clusters', for: 'Nvade\\Numerosis\\Filament\\TenantAdmin\\Clusters')
            ->pages([
                Dashboard::class,
                Billing::class,
            ])
            ->discoverWidgets(in: $root.'/Widgets', for: 'Nvade\\Numerosis\\Filament\\TenantAdmin\\Widgets')
            ->widgets([
                AccountWidget::class,
            ])
            ->middleware([
                TenancyServiceProvider::TENANCY_IDENTIFICATION,
                PreventAccessFromCentralDomains::class,
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                EnsureSessionMatchesTenant::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                PreventRequestForgery::class,
                UpdateUserLastSeenMiddleware::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authGuard('tenant')
            ->authMiddleware([
                Authenticate::class,
                EnsureTenantSubscriptionActive::class,
            ])
            ->plugins([
                ...(Features::enabled(ActivityLogFeature::NAME) ? [
                    ActivityLogPlugin::make()
                        ->navigationGroup('People')
                        ->cluster(TeamCluster::class),
                ] : []),
            ]);
    }
}
