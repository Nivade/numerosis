<?php

declare(strict_types=1);

namespace Nvade\NumerosisFilament\Providers;

use Filament\Facades\Filament;
use Filament\Panel;
use Filament\PanelProvider;
use Illuminate\Support\Facades\Config;
use Nvade\NumerosisFilament\NumerosisAdminPlugin;

/**
 * The package's own registration of the central admin panel — same shape as
 * `workbench/app/Providers/Filament/AdminPanelProvider.php`, which exists to
 * prove this file stays this thin. Everything panel-owned lives on
 * {@see NumerosisAdminPlugin}; this class exists only because Filament
 * itself requires a `PanelProvider` to call `Filament::registerPanel()`
 * from — there is no lower-ceremony registration point.
 *
 * `config('numerosis.panels.admin.provider')` is the escape hatch: a host
 * that sets it replaces this class entirely (see
 * `NumerosisServiceProvider::registerFilamentPanels()`), so a host wanting
 * its own panel definition never has to fight this one for the `'admin'`
 * panel id.
 */
class NumerosisAdminPanelProvider extends PanelProvider
{
    public function register(): void
    {
        if (! NumerosisAdminPlugin::shouldRegisterPanel()) {
            return;
        }

        Filament::registerPanel(
            fn (): Panel => $this->panel(Panel::make()),
        );
    }

    public function panel(Panel $panel): Panel
    {
        $panel = $panel->plugin(NumerosisAdminPlugin::make());

        if (Config::string('numerosis.panels.default', '') === 'admin') {
            $panel = $panel->default();
        }

        return $panel;
    }
}
