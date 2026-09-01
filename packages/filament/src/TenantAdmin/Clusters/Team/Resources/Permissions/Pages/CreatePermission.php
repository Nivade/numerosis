<?php

declare(strict_types=1);

namespace Nvade\NumerosisFilament\TenantAdmin\Clusters\Team\Resources\Permissions\Pages;

use Nvade\NumerosisFilament\Shared\Resources\Permissions\Pages\CreatePermission as BaseCreatePermission;
use Nvade\NumerosisFilament\TenantAdmin\Clusters\Team\Resources\Permissions\PermissionResource;

class CreatePermission extends BaseCreatePermission
{
    protected static string $resource = PermissionResource::class;
}
