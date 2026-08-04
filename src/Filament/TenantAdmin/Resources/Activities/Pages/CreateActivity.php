<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Filament\TenantAdmin\Resources\Activities\Pages;

use Nvade\Numerosis\Filament\TenantAdmin\Resources\Activities\ActivityResource;
use Filament\Resources\Pages\CreateRecord;

class CreateActivity extends CreateRecord
{
    protected static string $resource = ActivityResource::class;

    protected function getHeaderActions(): array
    {
        return [

        ];
    }
}
