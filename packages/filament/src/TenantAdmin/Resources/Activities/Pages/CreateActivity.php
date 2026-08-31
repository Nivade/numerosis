<?php

declare(strict_types=1);

namespace Nvade\NumerosisFilament\TenantAdmin\Resources\Activities\Pages;

use Filament\Resources\Pages\CreateRecord;
use Nvade\NumerosisFilament\TenantAdmin\Resources\Activities\ActivityResource;
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
