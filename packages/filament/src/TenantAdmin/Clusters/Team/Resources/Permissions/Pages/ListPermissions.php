<?php

declare(strict_types=1);

namespace Nvade\NumerosisFilament\TenantAdmin\Clusters\Team\Resources\Permissions\Pages;

use Nvade\NumerosisFilament\Shared\Resources\Permissions\Pages\ListPermissions as BaseListPermissions;
use Nvade\NumerosisFilament\TenantAdmin\Clusters\Team\Resources\Permissions\PermissionResource;

class ListPermissions extends BaseListPermissions
{
    protected static string $resource = PermissionResource::class;
}
