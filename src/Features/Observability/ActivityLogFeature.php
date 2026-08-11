<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Features\Observability;

use Nvade\Numerosis\Contracts\NamedFeature;

/**
 * The audit-log UI in the tenant panel.
 *
 * Remove it from `numerosis.features` to hide it. Activity is still
 * recorded and existing rows are untouched — this removes the interface, not
 * the audit trail.
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
        // Nothing to register: this feature is read at call time.
    }
}
