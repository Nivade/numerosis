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
     * No CreateAction: this resource's own create route used to insert a
     * bare `tenants` row and hand-roll a domain, which never runs
     * `ProvisionTenant`'s chain — the resulting tenant has no physical
     * database, no owner, and `provisioned_at` stays null forever. Every
     * real tenant must be created through {@see RegisterTenant}, the same
     * wizard self-serve signup uses, so staff-created and customer-created
     * tenants go through one provisioning path instead of two that drift.
     * See .claude/rules/tenant-provisioning.md.
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
