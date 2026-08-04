<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Filament\TenantAdmin\Resources;

use Filament\Resources\Resource;

class BaseResource extends Resource
{
    protected static bool $isScopedToTenant = false;
}
