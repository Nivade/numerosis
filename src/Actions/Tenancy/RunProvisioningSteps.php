<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Actions\Tenancy;

use Illuminate\Contracts\Queue\ShouldQueue;
use Lorisleiva\Actions\Concerns\AsAction;
use Nvade\Numerosis\Data\Tenancy\TenantProvisionData;
use Nvade\Numerosis\Models\Central\Tenant;

/**
 * Runs the provisioning steps from `numerosis.tenancy.provisioning.steps`, in
 * order, after the tenant database exists.
 *
 * The first step creates the tenant and runs earlier; every step after it
 * receives the tenant and the registration data, and must be idempotent
 * because the whole chain re-runs on retry.
 */
class RunProvisioningSteps implements ShouldQueue
{
    use AsAction;

    public function handle(Tenant $tenant, TenantProvisionData $data): void
    {
        /** @var non-empty-list<class-string> $steps */
        $steps = config('numerosis.tenancy.provisioning.steps', [CreateTenant::class]);

        array_shift($steps);

        foreach ($steps as $stepClass) {
            $stepClass::run($tenant, $data);
        }
    }
}
