<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Filament\Admin\Resources\Central\Modules\Pages;

use Nvade\Numerosis\Filament\Admin\Resources\Central\Modules\ModuleOfferingResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListModuleOfferings extends ListRecords
{
    protected static string $resource = ModuleOfferingResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
