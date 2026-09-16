<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Nvade\Numerosis\Actions\Tenancy\ReopenTenant;
use Nvade\Numerosis\Models\Central\Tenant;
use Nvade\Numerosis\Numerosis;

#[Description('Reopen a closed tenant, resuming its subscription when it has not lapsed')]
#[Signature('tenancy:reopen {tenant : The tenant id}')]
class ReopenTenantCommand extends Command
{
    public function handle(): int
    {
        $tenantId = (string) $this->argument('tenant');

        $tenant = Numerosis::model(Tenant::class)::find($tenantId);

        if (! $tenant instanceof Tenant) {
            $this->error("No tenant [{$tenantId}].");

            return self::FAILURE;
        }

        if (! $tenant->isClosed()) {
            $this->info("Tenant [{$tenantId}] is not closed.");

            return self::SUCCESS;
        }

        $resumed = ReopenTenant::run($tenant);

        $this->info($resumed
            ? "Reopened [{$tenantId}]; its subscription resumed."
            : "Reopened [{$tenantId}]; its subscription had lapsed, so the owner has to start a new one.");

        return self::SUCCESS;
    }
}
