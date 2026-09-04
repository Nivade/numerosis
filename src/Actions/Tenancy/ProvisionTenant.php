<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Actions\Tenancy;

use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Lorisleiva\Actions\Concerns\AsAction;
use Nvade\Numerosis\Contracts\Tenancy\ProvisionsTenant;
use Nvade\Numerosis\Data\Tenancy\TenantProvisionData;
use Nvade\Numerosis\Events\Tenancy\TenantProvisioningFailed;
use Nvade\Numerosis\Models\Central\Tenant;
use Nvade\Numerosis\Providers\TenancyServiceProvider;
use Throwable;

/**
 * Provisions a tenant: creates the tenant row synchronously, then runs the
 * database, ownership, subscription and finalization work as a queued chain on
 * the `provisioning` queue. Reach it through {@see ProvisionsTenant::queue()}
 * and never by dispatching it directly. Add your own steps via
 * `numerosis.tenancy.provisioning.steps`; each must be idempotent on retry.
 */
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
        $steps = config('numerosis.tenancy.provisioning.steps', [CreateTenant::class]);

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
     * Queues the rest of provisioning, under a per-domain lock held for the
     * whole chain. `ShouldBeUnique` cannot serve here: it only covers this
     * job, which returns as soon as the chain is dispatched, leaving the
     * checkout redirect and the Stripe webhook free to start a second one.
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
     * The database-creation jobs, included only when the tenant database does
     * not exist yet: all of them or none, since gating them individually
     * would re-seed a database that is already populated.
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
