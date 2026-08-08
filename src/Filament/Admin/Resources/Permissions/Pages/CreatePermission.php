<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Filament\Admin\Resources\Permissions\Pages;

use Filament\Resources\Pages\CreateRecord;
use Nvade\Numerosis\Filament\Admin\Resources\Permissions\PermissionResource;

class CreatePermission extends CreateRecord
{
    protected static string $resource = PermissionResource::class;
}
