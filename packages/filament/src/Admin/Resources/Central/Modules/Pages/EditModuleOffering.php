<?php

declare(strict_types=1);

namespace Nvade\NumerosisFilament\Admin\Resources\Central\Modules\Pages;

use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Nvade\NumerosisFilament\Admin\Resources\Central\Modules\ModuleOfferingResource;
use Override;

class EditModuleOffering extends EditRecord
{
    protected static string $resource = ModuleOfferingResource::class;

    #[Override]
    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
