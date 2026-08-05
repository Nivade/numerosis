<?php

declare(strict_types=1);

namespace Workbench\App\Providers\Filament;

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
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Config;
use Illuminate\View\Middleware\ShareErrorsFromSession;
use Nvade\Numerosis\Features\Ui\AdminPanelFeature;
use Nvade\Numerosis\Filament\Admin\Clusters\Billing\Widgets\BillingStatsWidget;
use Nvade\Numerosis\Filament\Admin\Clusters\Billing\Widgets\RevenueChartWidget;
use Nvade\Numerosis\Http\Middleware\Authenticate;
use Nvade\Numerosis\Support\Features;

/**
 * Minimal stand-in for the panel provider thin-app will own (Phase 7/8) —
 * only what package's own Filament resource/page tests need to exercise a
 * registered 'admin' panel. Not full parity with saas-m's version: no
 * shadcn theme, no module plugins — those are host/consumer concerns.
 */
class AdminPanelProvider extends PanelProvider
{
    public function register(): void
    {
        if (! Features::enabled(AdminPanelFeature::NAME)) {
            return;
        }

        Filament::registerPanel(
            fn (): Panel => $this->panel(Panel::make()),
        );
    }

    public function panel(Panel $panel): Panel
    {
        $root = dirname(__DIR__, 4).'/src/Filament/Admin';

        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->authGuard(Config::string('auth.defaults.guards.context.central'))
            ->login()
            ->colors([
                'primary' => Color::Amber,
            ])
            ->discoverResources(in: $root.'/Resources', for: 'Nvade\\Numerosis\\Filament\\Admin\\Resources')
            ->discoverClusters(in: $root.'/Clusters', for: 'Nvade\\Numerosis\\Filament\\Admin\\Clusters')
            ->discoverPages(in: $root.'/Pages', for: 'Nvade\\Numerosis\\Filament\\Admin\\Pages')
            ->pages([
                Dashboard::class,
            ])
            ->discoverWidgets(in: $root.'/Widgets', for: 'Nvade\\Numerosis\\Filament\\Admin\\Widgets')
            ->widgets([
                AccountWidget::class,
                BillingStatsWidget::class,
                RevenueChartWidget::class,
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
            ->authMiddleware([
                Authenticate::class,
            ]);
    }
}
