<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Filament\Admin\Resources\Central\Modules\Pages;

use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Nvade\Numerosis\Filament\Admin\Resources\Central\Modules\ModuleOfferingResource;
use Override;

class ListModuleOfferings extends ListRecords
{
    protected static string $resource = ModuleOfferingResource::class;

    #[Override]
    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
