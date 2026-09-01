<?php

declare(strict_types=1);

namespace Nvade\NumerosisFilament\TenantAdmin\Clusters\Team\Resources\Permissions\Pages;

use Nvade\NumerosisFilament\Shared\Resources\Permissions\Pages\EditPermission as BaseEditPermissions;
use Nvade\NumerosisFilament\TenantAdmin\Clusters\Team\Resources\Permissions\PermissionResource;

class EditPermission extends BaseEditPermissions
{
    protected static string $resource = PermissionResource::class;
}
