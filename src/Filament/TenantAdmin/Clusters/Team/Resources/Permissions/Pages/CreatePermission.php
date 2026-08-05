<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Filament\TenantAdmin\Clusters\Team\Resources\Permissions\Pages;

use Nvade\Numerosis\Filament\App\Resources\Permissions\Pages\CreatePermission as BaseCreatePermission;
use Nvade\Numerosis\Filament\TenantAdmin\Clusters\Team\Resources\Permissions\PermissionResource;

class CreatePermission extends BaseCreatePermission
{
    protected static string $resource = PermissionResource::class;
}
