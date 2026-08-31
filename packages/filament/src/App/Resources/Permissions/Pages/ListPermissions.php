<?php

declare(strict_types=1);

namespace Nvade\NumerosisFilament\App\Resources\Permissions\Pages;

use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Override;

class ListPermissions extends ListRecords
{
    #[Override]
    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
