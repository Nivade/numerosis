<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Filament\TenantAdmin\Resources\Activities;

use Nvade\Numerosis\Features\Observability\ActivityLogFeature;
use Nvade\Numerosis\Support\Features;
use Override;

/**
 * This file is autoloaded unconditionally the moment the tenant panel boots
 * — {@see \Nvade\Numerosis\Filament\NumerosisTenantPlugin} discovers every
 * class under `Resources/` by directory scan, regardless of
 * {@see ActivityLogFeature}'s on/off state. Filament itself is guaranteed
 * present whenever that happens (this directory is only scanned from
 * inside a Filament panel boot), but `alizharb/filament-activity-log` is
 * a separate, independently optional dependency — `extends
 * ActivityLogResource` would fatal on autoload for a host with Filament
 * installed but not that package. The conditional definition keeps this
 * file loadable either way; Filament's own discovery only registers a
 * discovered class when it is a `Resource` subclass, so the fallback below
 * is simply skipped rather than registered.
 */
if (class_exists(\AlizHarb\ActivityLog\Resources\ActivityLogs\ActivityLogResource::class)) {
    class ActivityResource extends \AlizHarb\ActivityLog\Resources\ActivityLogs\ActivityLogResource
    {
        protected static bool $isScopedToTenant = false;

        #[Override]
        public static function canAccess(): bool
        {
            return Features::enabled(ActivityLogFeature::NAME) && parent::canAccess();
        }

        #[Override]
        public static function shouldRegisterNavigation(): bool
        {
            return Features::enabled(ActivityLogFeature::NAME) && parent::shouldRegisterNavigation();
        }
    }
} else {
    class ActivityResource
    {
        //
    }
}
