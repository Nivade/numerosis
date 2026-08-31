<?php

declare(strict_types=1);

namespace Nvade\NumerosisFilament\TenantAdmin\Resources\Activities\Pages;

use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Nvade\NumerosisFilament\TenantAdmin\Resources\Activities\ActivityResource;
use Override;

class ListActivities extends ListRecords
{
    protected static string $resource = ActivityResource::class;

    #[Override]
    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
