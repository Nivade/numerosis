<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Providers\Filament;

use Filament\Facades\Filament;
use Filament\Panel;
use Filament\PanelProvider;
use Illuminate\Support\Facades\Config;
use Nvade\Numerosis\Filament\NumerosisTenantPlugin;

/**
 * @see NumerosisAdminPanelProvider — same reasoning, tenant side.
 *
 * `config('numerosis.panels.tenant.provider')` replaces this class entirely.
 */
class NumerosisTenantPanelProvider extends PanelProvider
{
    public function register(): void
    {
        if (! NumerosisTenantPlugin::shouldRegisterPanel()) {
            return;
        }

        Filament::registerPanel(
            fn (): Panel => $this->panel(Panel::make()),
        );
    }

    public function panel(Panel $panel): Panel
    {
        $panel = $panel->plugin(NumerosisTenantPlugin::make());

        if (Config::string('numerosis.panels.default', '') === 'tenant') {
            $panel = $panel->default();
        }

        return $panel;
    }
}
