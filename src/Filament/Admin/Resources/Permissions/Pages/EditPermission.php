<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Filament\Admin\Resources\Permissions\Pages;

use Nvade\Numerosis\Filament\Admin\Resources\Permissions\PermissionResource;
use Filament\Resources\Pages\EditRecord;

class EditPermission extends EditRecord
{
    protected static string $resource = PermissionResource::class;
}
