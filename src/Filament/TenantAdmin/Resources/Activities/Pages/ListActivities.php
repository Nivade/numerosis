<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Filament\TenantAdmin\Resources\Activities\Pages;

use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Nvade\Numerosis\Filament\TenantAdmin\Resources\Activities\ActivityResource;
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
