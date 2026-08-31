<?php

declare(strict_types=1);

namespace Nvade\NumerosisFilament\TenantAdmin\Resources\Activities\Pages;

use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Nvade\NumerosisFilament\TenantAdmin\Resources\Activities\ActivityResource;
use Override;

class EditActivity extends EditRecord
{
    protected static string $resource = ActivityResource::class;

    #[Override]
    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
