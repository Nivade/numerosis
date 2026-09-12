<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Actions\Tenancy;

use Lorisleiva\Actions\Concerns\AsAction;
use Nvade\Numerosis\Contracts\Tenancy\ProvisioningStep;
use Nvade\Numerosis\Models\Central\TenantProvision;
use Stancl\Tenancy\Jobs\MigrateDatabase;

/**
 * Runs the tenant migrations. Safe to re-run: the migration table decides what
 * is outstanding.
 */
class MigrateTenantDatabase implements ProvisioningStep
{
    use AsAction;

    public function handle(TenantProvision $provision): void
    {
        $tenant = $provision->tenant()->firstOrFail();

        app()->call([new MigrateDatabase($tenant), 'handle']);
    }
}
