<?php

declare(strict_types=1);

namespace Nvade\NumerosisFilament\Features;

use Nvade\Numerosis\Contracts\NamedFeature;

/**
 * The central admin panel at `/admin`.
 *
 * Remove it from `numerosis.features` and the panel is never registered with
 * Filament — for running headless, or fronting the package with an admin UI
 * of your own.
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
        // Nothing to register: this feature is read at call time.
    }
}
