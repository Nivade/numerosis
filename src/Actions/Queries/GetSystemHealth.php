<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Actions\Queries;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Lorisleiva\Actions\Concerns\AsAction;
use Nvade\Numerosis\Actions\Tenancy\ProvisionTenant;
use Nvade\Numerosis\Cache\CacheKeys;
use Nvade\Numerosis\Cache\CacheTtl;
use Nvade\Numerosis\Cache\GlobalCache;
use Nvade\Numerosis\Data\Observability\HealthReport;
use Nvade\Numerosis\Data\Observability\ProvisioningHealth;
use Nvade\Numerosis\Enums\Tenancy\TenantProvisionStatus;
use Nvade\Numerosis\Models\Central\TenantProvision;
use Nvade\Numerosis\Numerosis;
use Throwable;

/**
 * The one set of counts the health endpoint, the staff screens and the
 * operator alert all read.
 *
 * Cached for a few seconds so a monitor polling every second cannot turn the
 * endpoint into load of its own.
 */
class GetSystemHealth
{
    use AsAction;

    public function handle(): HealthReport
    {
        // The array is cached instead of the Data object. A store whose
        // `serializable_classes` does not name these classes reads an object
        // back as `__PHP_Incomplete_Class`, and every poll would re-measure.
        $cached = GlobalCache::remember(
            CacheKeys::healthReport(),
            CacheTtl::healthReport(),
            fn (): array => $this->measure()->toArray(),
        );

        return HealthReport::from($cached);
    }

    private function measure(): HealthReport
    {
        $reachable = $this->centralDatabaseReachable();

        return new HealthReport(
            $reachable,
            GetQueueHealth::run(ProvisionTenant::QUEUE),
            $reachable ? $this->provisioning() : new ProvisioningHealth(0, 0, 0),
            $this->schedulerLastRunSeconds(),
        );
    }

    private function centralDatabaseReachable(): bool
    {
        try {
            DB::connection(Config::string('numerosis.tenancy.central_connection', 'central'))->select('select 1');

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    private function provisioning(): ProvisioningHealth
    {
        $provisions = Numerosis::model(TenantProvision::class);

        return new ProvisioningHealth(
            $provisions::query()
                ->where('status', TenantProvisionStatus::Failed)
                ->where('failed_at', '>=', now()->subHour())
                ->count(),
            $provisions::query()
                ->where('status', TenantProvisionStatus::Provisioning)
                ->where('provisioning_started_at', '<', now()->subMinutes(TenantProvision::STALE_AFTER_MINUTES))
                ->count(),
            $provisions::query()->where('status', TenantProvisionStatus::Provisioning)->count(),
        );
    }

    private function schedulerLastRunSeconds(): ?int
    {
        $beat = GlobalCache::store()->get(CacheKeys::schedulerHeartbeat());

        return is_numeric($beat) ? max(0, now()->getTimestamp() - (int) $beat) : null;
    }
}
