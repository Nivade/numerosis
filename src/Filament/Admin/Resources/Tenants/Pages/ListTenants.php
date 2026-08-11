<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Filament\Admin\Resources\Tenants\Pages;

use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;
use Nvade\Numerosis\Filament\Admin\Pages\RegisterTenant;
use Nvade\Numerosis\Filament\Admin\Resources\Tenants\TenantResource;
use Override;

class ListTenants extends ListRecords
{
    protected static string $resource = TenantResource::class;

    /**
     * Offers {@see RegisterTenant} rather than a plain create action, so
     * staff-created tenants go through the same provisioning as self-serve
     * signups. Inserting a tenant row directly leaves it with no database
     * and no owner.
     */
    #[Override]
    protected function getHeaderActions(): array
    {
        return [
            Action::make('registerTenant')
                ->label('New Tenant')
                ->icon('heroicon-o-plus')
                ->url(fn (): string => RegisterTenant::getUrl()),
        ];
    }
}
