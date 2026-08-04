<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Filament\TenantAdmin\Resources\Modules\Pages;

use Nvade\Numerosis\Filament\TenantAdmin\Resources\Modules\ModuleResource;
use Filament\Resources\Pages\ListRecords;

class ListModules extends ListRecords
{
    protected static string $resource = ModuleResource::class;
}
