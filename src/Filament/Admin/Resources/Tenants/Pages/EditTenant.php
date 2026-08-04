<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Filament\Admin\Resources\Tenants\Pages;

use Nvade\Numerosis\Filament\Admin\Resources\Tenants\TenantResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditTenant extends EditRecord
{
    protected static string $resource = TenantResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
