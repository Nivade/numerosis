<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Filament\TenantAdmin\Resources\Activities;

use AlizHarb\ActivityLog\Resources\ActivityLogs\ActivityLogResource;
use Nvade\Numerosis\Features\Observability\ActivityLogFeature;
use Nvade\Numerosis\Support\Features;

class ActivityResource extends ActivityLogResource
{
    protected static bool $isScopedToTenant = false;

    public static function canAccess(): bool
    {
        return Features::enabled(ActivityLogFeature::NAME) && parent::canAccess();
    }

    public static function shouldRegisterNavigation(): bool
    {
        return Features::enabled(ActivityLogFeature::NAME) && parent::shouldRegisterNavigation();
    }
}
