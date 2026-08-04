<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Actions\Tenancy;

use Nvade\Numerosis\Contracts\Tenancy\ProvisionsTenant;
use Nvade\Numerosis\Data\Tenancy\TenantProvisionData;
use Nvade\Numerosis\Events\Tenancy\TenantProvisioningFailed;
use Nvade\Numerosis\Models\Central\Tenant;
use Nvade\Numerosis\Providers\TenancyServiceProvider;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Lorisleiva\Actions\Concerns\AsAction;
use Throwable;

// See .claude/rules/tenant-provisioning.md.
class ProvisionTenant implements ProvisionsTenant, ShouldBeUnique, ShouldQueue
{
    use AsAction;

    public int $jobTries = 5;

    public int $jobBackoff = 10;

    public int $jobUniqueFor = 300;

    public string $jobQueue = 'provisioning';

    public function queue(TenantProvisionData $data): void
    {
        static::dispatch($data);
    }

    public function getJobUniqueId(TenantProvisionData $data): string
    {
        return $data->registration->domain;
    }

    public function handle(TenantProvisionData $data): void
    {
        /** @var non-empty-list<class-string> $steps */
        $steps = config('numerosis-tenancy.provisioning.steps', [CreateTenant::class]);

        /** @var class-string $firstStep */
        $firstStep = $steps[0];

        /** @var Tenant $tenant */
        $tenant = $firstStep::run($data->registration);

        $this->dispatchProvisioningChain($tenant, $data);
    }

    public function jobFailed(Throwable $e, TenantProvisionData $data): void
    {
        MarkProvisionFailed::run($data->registration->domain, $e->getMessage());

        event(new TenantProvisioningFailed($data->registration->domain, $data->registration->global_id));
    }

    /**
     * Chains the rest of provisioning as real queued jobs on the
     * `provisioning` queue, guarded by a non-blocking per-domain lock —
     * see Fix 3 in .claude/rules/tenant-provisioning.md for why
     * `ShouldBeUnique` alone is not enough once this job itself returns in
     * milliseconds.
     */
    private function dispatchProvisioningChain(Tenant $tenant, TenantProvisionData $data): void
    {
        $domain = (string) $tenant->getTenantKey();
        $globalId = $data->registration->global_id;

        if (! Cache::lock("tenant-chain:{$domain}", 900)->get()) {
            return;
        }

        Bus::chain([
            ...$this->databaseJobs($tenant),
            RunProvisioningSteps::makeJob($tenant, $data),
            ...($data->stripeSubscriptionId ? [LinkTenantSubscription::makeJob($tenant, $data)] : []),
            FinalizeTenantProvisioning::makeJob($tenant),
        ])
            ->onQueue('provisioning')
            ->catch(function (Throwable $e) use ($domain, $globalId): void {
                Cache::lock("tenant-chain:{$domain}")->forceRelease();
                MarkProvisionFailed::run($domain, $e->getMessage());
                event(new TenantProvisioningFailed($domain, $globalId));
            })
            ->dispatch();
    }

    /**
     * Gated on the whole `TenancyServiceProvider::$tenantCreatedJobs`
     * segment at once, not per job — see Fix 2 in
     * .claude/rules/tenant-provisioning.md. Per-job gating would re-run a
     * seed step against an already-populated database on retry.
     *
     * @return array<int, ShouldQueue>
     */
    private function databaseJobs(Tenant $tenant): array
    {
        $database = $tenant->database()->getName() ?? '';

        if ($database !== '' && $tenant->database()->manager()->databaseExists($database)) {
            return [];
        }

        return array_map(function (string $jobClass) use ($tenant): ShouldQueue {
            $job = new $jobClass($tenant);

            assert($job instanceof ShouldQueue);

            return $job;
        }, TenancyServiceProvider::$tenantCreatedJobs);
    }
}
