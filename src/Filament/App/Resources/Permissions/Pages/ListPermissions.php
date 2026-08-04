<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Filament\Nvade\Numerosis\Resources\Permissions\Pages;

use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListPermissions extends ListRecords
{
    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
