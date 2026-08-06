<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Actions\Tenancy;

use Illuminate\Contracts\Queue\ShouldQueue;
use Lorisleiva\Actions\Concerns\AsAction;
use Nvade\Numerosis\Data\Tenancy\TenantProvisionData;
use Nvade\Numerosis\Models\Central\Tenant;

// See .claude/rules/tenant-provisioning.md.
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
