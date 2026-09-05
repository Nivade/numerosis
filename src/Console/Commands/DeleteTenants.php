<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Nvade\Numerosis\Models\Central\Tenant;
use Nvade\Numerosis\Support\Numerosis;

#[Description('Delete tenants by id, or every tenant, dropping each database with the row')]
#[Signature('tenants:delete
                            {tenants?* : Tenant IDs to delete}
                            {--all : Delete all tenants}')]
class DeleteTenants extends Command
{
    public function handle(): void
    {
        $tenantClass = Numerosis::model(Tenant::class);

        if ($this->option('all')) {
            $tenantClass::each(fn ($tenant) => $tenant->delete());

            return;
        }

        /** @var list<string> $tenantIds */
        $tenantIds = (array) $this->argument('tenants');

        foreach ($tenantIds as $tenantId) {
            $tenantClass::find($tenantId)?->delete();
        }
    }
}
