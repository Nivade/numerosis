<?php

declare(strict_types=1);

namespace Nvade\NumerosisFilament\Admin\Resources\Tenants\Pages;

use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;
use Nvade\NumerosisFilament\Admin\Pages\RegisterTenant;
use Nvade\NumerosisFilament\Admin\Resources\Tenants\TenantResource;
use Override;

class ListTenants extends ListRecords
{
    protected static string $resource = TenantResource::class;

    /**
     * Offers {@see RegisterTenant} rather than a plain create action, so
     * staff-created tenants go through the same provisioning as self-serve
     * signups. Inserting a tenant row directly leaves it with no database
     * and no owner — which is also why there is no fallback action when the
     * wizard is absent: no action at all is the honest state, and a create
     * form here would produce broken tenants.
     */
    #[Override]
    protected function getHeaderActions(): array
    {
        if (RegisterTenant::wizardComponent() === null) {
            return [];
        }

        return [
            Action::make('registerTenant')
                ->label('New Tenant')
                ->icon('heroicon-o-plus')
                ->url(fn (): string => RegisterTenant::getUrl()),
        ];
    }
}
