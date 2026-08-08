<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Filament\TenantAdmin\Resources\Activities\Pages;

use Filament\Resources\Pages\CreateRecord;
use Nvade\Numerosis\Filament\TenantAdmin\Resources\Activities\ActivityResource;
use Override;

class CreateActivity extends CreateRecord
{
    protected static string $resource = ActivityResource::class;

    #[Override]
    protected function getHeaderActions(): array
    {
        return [

        ];
    }
}
