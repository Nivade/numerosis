<?php

declare(strict_types=1);

namespace Nvade\NumerosisFilament\Admin\Resources\Central\Modules\Pages;

use Filament\Resources\Pages\CreateRecord;
use Nvade\NumerosisFilament\Admin\Resources\Central\Modules\ModuleOfferingResource;

class CreateModuleOffering extends CreateRecord
{
    protected static string $resource = ModuleOfferingResource::class;
}
