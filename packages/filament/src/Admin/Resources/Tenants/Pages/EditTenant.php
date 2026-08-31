<?php

declare(strict_types=1);

namespace Nvade\NumerosisFilament\Admin\Resources\Tenants\Pages;

use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Nvade\NumerosisFilament\Admin\Resources\Tenants\TenantResource;
use Override;

class EditTenant extends EditRecord
{
    protected static string $resource = TenantResource::class;

    #[Override]
    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
