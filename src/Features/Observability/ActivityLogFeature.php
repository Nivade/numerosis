<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Features\Observability;

use Nvade\Numerosis\Contracts\NamedFeature;

/**
 * The audit-log surface: AlizHarb\ActivityLog\ActivityLogPlugin in the tenant
 * panel and Nvade\Numerosis\Filament\TenantAdmin\Resources\Activities on top of it.
 * Removing this class from config('numerosis.features') stops the panel
 * registering the plugin and hides the resource — a third-party audit-log
 * package is not something every consumer wants forced on them.
 *
 * bootstrap() is empty for the same reason ModuleSystemFeature's is: the
 * panel provider asks at boot, the resource asks at call time, and neither
 * needs a registration performed from here. Presence in the array is the
 * toggle.
 *
 * Disabling does not stop spatie/laravel-activitylog from *writing* — models
 * composing LogsActivity keep recording, and existing `activity_log` rows
 * are untouched. This removes the UI, not the audit trail. Stopping the
 * writes is a separate decision, and a destructive one for compliance-shaped
 * deployments.
 */
class ActivityLogFeature implements NamedFeature
{
    public const NAME = 'activity_log';

    public static function featureName(): string
    {
        return self::NAME;
    }

    public function bootstrap(): void
    {
        // Nothing to register — see the class docblock.
    }
}
