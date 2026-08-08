<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Filament\Admin\Resources\Roles\Pages;

use Filament\Resources\Pages\CreateRecord;
use Nvade\Numerosis\Filament\Admin\Resources\Roles\RoleResource;

class CreateRole extends CreateRecord
{
    protected static string $resource = RoleResource::class;
}
