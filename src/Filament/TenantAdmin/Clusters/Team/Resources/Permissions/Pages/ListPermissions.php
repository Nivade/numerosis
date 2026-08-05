<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Filament\TenantAdmin\Clusters\Team\Resources\Permissions\Pages;

use Nvade\Numerosis\Filament\App\Resources\Permissions\Pages\ListPermissions as BaseListPermissions;
use Nvade\Numerosis\Filament\TenantAdmin\Clusters\Team\Resources\Permissions\PermissionResource;

class ListPermissions extends BaseListPermissions
{
    protected static string $resource = PermissionResource::class;
}
