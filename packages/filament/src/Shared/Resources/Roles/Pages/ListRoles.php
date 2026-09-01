<?php

declare(strict_types=1);

namespace Nvade\NumerosisFilament\Shared\Resources\Roles\Pages;

use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Override;

class ListRoles extends ListRecords
{
    #[Override]
    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
