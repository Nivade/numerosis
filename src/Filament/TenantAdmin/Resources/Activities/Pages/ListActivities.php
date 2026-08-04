<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Filament\TenantAdmin\Resources\Activities\Pages;

use Nvade\Numerosis\Filament\TenantAdmin\Resources\Activities\ActivityResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListActivities extends ListRecords
{
    protected static string $resource = ActivityResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
