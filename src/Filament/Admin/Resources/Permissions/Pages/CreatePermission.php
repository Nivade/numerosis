<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Filament\Admin\Resources\Permissions\Pages;

use Nvade\Numerosis\Filament\Admin\Resources\Permissions\PermissionResource;
use Filament\Resources\Pages\CreateRecord;

class CreatePermission extends CreateRecord
{
    protected static string $resource = PermissionResource::class;
}
