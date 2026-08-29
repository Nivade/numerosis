<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Actions\Tenancy;

use Illuminate\Contracts\Queue\ShouldQueue;
use Lorisleiva\Actions\Concerns\AsAction;
use Lorisleiva\Actions\Decorators\JobDecorator;
use Nvade\Numerosis\Concerns\TagsSentryScopeWithTenant;
use Nvade\Numerosis\Events\Tenancy\TenantProvisioningFailed;
use Nvade\Numerosis\Models\Central\CentralUser;
use Nvade\Numerosis\Models\Central\Tenant;
use Throwable;

/**
 * Final provisioning step: promotes the first non-bot user to admin, then
 * signals that provisioning finished.
 *
 * Must stay last. It reads users out of the tenant database, and it is the
 * only emitter of the "ready" signal the UI waits on — if it never completes,
 * that UI spins forever, which is why it retries generously.
 *
 * Typed against this package's own {@see Tenant} rather than stancl's
 * `Contracts\Tenant`: {@see PromoteFirstUserToAdmin} requires the concrete
 * model, and the only caller ({@see ProvisionTenant::dispatchProvisioningChain()})
 * has always passed one. The broader hint bought nothing and forced
 * `owner()` into an `instanceof` that could never be false.
 */
class FinalizeTenantProvisioning implements ShouldQueue
{
    use AsAction;
    use TagsSentryScopeWithTenant;

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
        return $tenant->owner();
    }
}
