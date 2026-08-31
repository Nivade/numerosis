<?php

declare(strict_types=1);

namespace Nvade\NumerosisFilament\TenantAdmin\Resources\Modules\Pages;

use Filament\Resources\Pages\ListRecords;
use Nvade\NumerosisFilament\TenantAdmin\Resources\Modules\ModuleResource;

class ListModules extends ListRecords
{
    protected static string $resource = ModuleResource::class;
}
