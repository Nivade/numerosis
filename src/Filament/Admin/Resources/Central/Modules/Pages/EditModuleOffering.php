<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Filament\Admin\Resources\Central\Modules\Pages;

use Nvade\Numerosis\Filament\Admin\Resources\Central\Modules\ModuleOfferingResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditModuleOffering extends EditRecord
{
    protected static string $resource = ModuleOfferingResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
