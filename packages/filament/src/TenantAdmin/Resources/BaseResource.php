<?php

declare(strict_types=1);

namespace Nvade\NumerosisFilament\TenantAdmin\Resources;

use Filament\Resources\Resource;

class BaseResource extends Resource
{
    protected static bool $isScopedToTenant = false;
}
