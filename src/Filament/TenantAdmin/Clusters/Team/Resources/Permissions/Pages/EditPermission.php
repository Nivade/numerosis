<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Filament\TenantAdmin\Clusters\Team\Resources\Permissions\Pages;

use Nvade\Numerosis\Filament\Nvade\Numerosis\Resources\Permissions\Pages\EditPermission as BaseEditPermissions;
use Nvade\Numerosis\Filament\TenantAdmin\Clusters\Team\Resources\Permissions\PermissionResource;

class EditPermission extends BaseEditPermissions
{
    protected static string $resource = PermissionResource::class;
}
