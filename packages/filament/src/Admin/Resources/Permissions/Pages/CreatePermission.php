<?php

declare(strict_types=1);

namespace Nvade\NumerosisFilament\Admin\Resources\Permissions\Pages;

use Filament\Resources\Pages\CreateRecord;
use Nvade\NumerosisFilament\Admin\Resources\Permissions\PermissionResource;

class CreatePermission extends CreateRecord
{
    protected static string $resource = PermissionResource::class;
}
