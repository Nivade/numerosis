<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Filament\Admin\Resources\Tenants\Pages;

use Nvade\Numerosis\Filament\Admin\Resources\Tenants\TenantResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListTenants extends ListRecords
{
    protected static string $resource = TenantResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
