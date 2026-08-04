<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Features\Ui;

use Nvade\Numerosis\Contracts\NamedFeature;

/**
 * The tenant admin panel (`{tenant}.<domain>/`) —
 * `Nvade\Numerosis\Providers\Filament\TenantAdminPanelProvider`. Remove this class from
 * config('numerosis.features') and a consumer that fronts tenants with their
 * own UI no longer gets this panel registered with Filament at all.
 *
 * bootstrap() is empty: the gate lives in
 * TenantAdminPanelProvider::register(), alongside the existing
 * central-domain guard already there (both must pass for
 * Filament::registerPanel() to run — see that method's own docblock for why
 * the central-domain check exists). Same reasoning as AdminPanelFeature for
 * why this can't be a bootstrap/providers.php-level check.
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
        // Nothing to register — see the class docblock.
    }
}
