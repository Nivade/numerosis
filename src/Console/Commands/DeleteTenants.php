<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Console\Commands;

use Nvade\Numerosis\Models\Central\Tenant;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Description('Command description')]
#[Signature('tenants:delete
                            {tenants?* : Tenant IDs to delete}
                            {--all : Delete all tenants}')]
class DeleteTenants extends Command
{
    public function handle(): void
    {
        if ($this->option('all')) {
            Tenant::each(fn ($tenant) => $tenant->delete());

            return;
        }

        foreach ($this->argument('tenants') as $tenantId) {
            Tenant::find($tenantId)?->delete();
        }
    }
}
