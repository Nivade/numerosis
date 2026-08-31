<?php

declare(strict_types=1);

namespace Nvade\NumerosisFilament\Admin\Resources\Roles\Pages;

use Filament\Resources\Pages\CreateRecord;
use Nvade\NumerosisFilament\Admin\Resources\Roles\RoleResource;

class CreateRole extends CreateRecord
{
    protected static string $resource = RoleResource::class;
}
