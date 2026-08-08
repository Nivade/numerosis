<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Filament\TenantAdmin\Resources\Activities\Pages;

use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Nvade\Numerosis\Filament\TenantAdmin\Resources\Activities\ActivityResource;
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
