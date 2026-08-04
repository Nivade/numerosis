<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Filament\Admin\Resources\Central\Modules\Pages;

use Nvade\Numerosis\Filament\Admin\Resources\Central\Modules\ModuleOfferingResource;
use Filament\Resources\Pages\CreateRecord;

class CreateModuleOffering extends CreateRecord
{
    protected static string $resource = ModuleOfferingResource::class;
}
