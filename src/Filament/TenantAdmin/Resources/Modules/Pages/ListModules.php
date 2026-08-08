<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Filament\TenantAdmin\Resources\Modules\Pages;

use Filament\Resources\Pages\ListRecords;
use Nvade\Numerosis\Filament\TenantAdmin\Resources\Modules\ModuleResource;

class ListModules extends ListRecords
{
    protected static string $resource = ModuleResource::class;
}
