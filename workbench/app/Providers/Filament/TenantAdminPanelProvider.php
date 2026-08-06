<?php

declare(strict_types=1);

namespace Workbench\App\Providers\Filament;

use Filament\Facades\Filament;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Nvade\Numerosis\Filament\NumerosisTenantPlugin;

/**
 * @see AdminPanelProvider — same reasoning, tenant side.
 *
 * The middleware order this panel depends on (identification first,
 * `EnsureSessionMatchesTenant` after `StartSession`, a pinned `authGuard`)
 * used to be hand-copied here and in thin-app. Getting that order wrong while
 * copying produces a cross-tenant identity leak rather than an error, which
 * is the strongest argument for it living in one place;
 * {@see NumerosisTenantPlugin} is that place.
 */
class TenantAdminPanelProvider extends PanelProvider
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
        return $panel
            ->plugin(NumerosisTenantPlugin::make())
            ->colors([
                'primary' => Color::Rose,
            ]);
    }
}
