<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Actions\Tenancy;

use Nvade\Numerosis\Concerns\TagsSentryScopeWithTenant;
use Nvade\Numerosis\Events\Tenancy\TenantProvisioningFailed;
use Nvade\Numerosis\Models\Central\CentralUser;
use Nvade\Numerosis\Models\Central\Tenant as CentralTenant;
use Illuminate\Contracts\Queue\ShouldQueue;
use Lorisleiva\Actions\Concerns\AsAction;
use Lorisleiva\Actions\Decorators\JobDecorator;
use Stancl\Tenancy\Contracts\Tenant;
use Throwable;

/**
 * Final step of tenant provisioning: promotes the first non-bot user to
 * admin, then emits the "provisioning finished" signal via
 * MarkTenantProvisioned. See that class and
 * .claude/rules/tenant-provisioning.md for why this ordering matters.
 */
class FinalizeTenantProvisioning implements ShouldQueue
{
    use AsAction;
    use TagsSentryScopeWithTenant;

    /**
     * The owner membership is written a few statements after the TenantCreated
     * event fires, so allow generous retries for that narrow window.
     */
    public int $jobTries = 20;

    public int $jobBackoff = 3;

    public bool $jobDeleteWhenMissingModels = true;

    public function configureJob(JobDecorator $job): void
    {
        $job->afterCommit();
    }

    public function handle(Tenant $tenant): void
    {
        PromoteFirstUserToAdmin::run($tenant);
        MarkTenantProvisioned::run($tenant);
    }

    public function jobFailed(Throwable $e, Tenant $tenant): void
    {
        $this->tagSentryScopeWithTenant((string) $tenant->getTenantKey());

        MarkProvisionFailed::run((string) $tenant->getTenantKey(), $e->getMessage());

        event(new TenantProvisioningFailed((string) $tenant->getTenantKey(), $this->owner($tenant)?->global_id));
    }

    private function owner(Tenant $tenant): ?CentralUser
    {
        return $tenant instanceof CentralTenant ? $tenant->owner() : null;
    }
}
