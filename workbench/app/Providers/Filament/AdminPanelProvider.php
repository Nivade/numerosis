<?php

declare(strict_types=1);

namespace Workbench\App\Providers\Filament;

use Filament\Facades\Filament;
use Filament\Panel;
use Filament\PanelProvider;
use Nvade\Numerosis\Filament\NumerosisAdminPlugin;

/**
 * What a consuming app's own `AdminPanelProvider` looks like, and the reason
 * this file is worth reading: it is now short enough to be an honest
 * stand-in.
 *
 * It used to be an 88-line copy of the panel definition, and thin-app held a
 * 113-line copy of the same thing — which had already drifted (this copy was
 * missing `->registration()`, `->profile()`, `->persistentMiddleware()`,
 * `->domains()` and the navigation groups). Everything package-owned lives in
 * {@see NumerosisAdminPlugin} now, so the harness exercises exactly the
 * definition a consumer gets rather than an approximation of it.
 *
 * No `->colors()` call (design-system-unification Phase 4): the plugin's
 * `->theme()` remaps Filament's colour vars from
 * resources/css/tokens.css, and a `->colors()` call here would fight
 * `FilamentColor::register()`'s per-container memoisation rather than
 * override anything (Phase 1 audit §1.7).
 */
class AdminPanelProvider extends PanelProvider
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
        return $panel
            ->default()
            ->plugin(NumerosisAdminPlugin::make());
    }
}
