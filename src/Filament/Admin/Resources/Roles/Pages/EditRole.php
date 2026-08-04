<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Filament\Admin\Resources\Roles\Pages;

use Nvade\Numerosis\Filament\Admin\Resources\Roles\RoleResource;
use Filament\Resources\Pages\EditRecord;

class EditRole extends EditRecord
{
    protected static string $resource = RoleResource::class;
}
