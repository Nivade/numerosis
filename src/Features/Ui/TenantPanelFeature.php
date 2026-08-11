<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Features\Ui;

use Nvade\Numerosis\Contracts\NamedFeature;

/**
 * The tenant admin panel served at `{tenant}.<domain>`.
 *
 * Remove it from `numerosis.features` and the panel is never registered with
 * Filament — for fronting tenants with a UI of your own.
 */
class TenantPanelFeature implements NamedFeature
{
    public const NAME = 'ui.tenant_panel';

    public static function featureName(): string
    {
        return self::NAME;
    }

    public function bootstrap(): void
    {
        // Nothing to register: this feature is read at call time.
    }
}
