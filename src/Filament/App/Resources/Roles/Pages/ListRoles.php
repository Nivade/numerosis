<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Filament\Nvade\Numerosis\Resources\Roles\Pages;

use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListRoles extends ListRecords
{
    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
