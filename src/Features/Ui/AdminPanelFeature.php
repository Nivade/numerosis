<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Features\Ui;

use Nvade\Numerosis\Contracts\NamedFeature;

/**
 * The central admin panel (`/admin`) — `Nvade\Numerosis\Providers\Filament\AdminPanelProvider`.
 * Remove this class from config('numerosis.features') and a consumer that
 * runs numerosis headless, or fronts it with their own admin UI, no longer
 * gets Filament's central panel registered with Filament at all.
 *
 * bootstrap() is empty: the gate lives in
 * AdminPanelProvider::register(), which checks Features::enabled() before
 * calling Filament::registerPanel(). It cannot live in
 * bootstrap/providers.php instead — that file runs before config/.env load,
 * so Features::enabled() (which reads config('numerosis.features')) isn't
 * answerable there yet. The provider class is always instantiated; only
 * whether it registers a panel with Filament is conditional.
 */
class AdminPanelFeature implements NamedFeature
{
    public const NAME = 'ui.admin_panel';

    public static function featureName(): string
    {
        return self::NAME;
    }

    public function bootstrap(): void
    {
        // Nothing to register — see the class docblock.
    }
}
