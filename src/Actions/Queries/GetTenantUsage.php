<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Actions\Queries;

use Illuminate\Support\Collection;
use Lorisleiva\Actions\Concerns\AsAction;
use Nvade\Numerosis\Contracts\Billing\UsageCounter;
use Nvade\Numerosis\Data\Billing\BillingPeriod;
use Nvade\Numerosis\Data\Billing\MeterDefinitionData;
use Nvade\Numerosis\Data\Billing\MeterUsage;
use Nvade\Numerosis\Models\Central\Tenant;

/**
 * What the tenant has used this period, per meter, for the usage screen and
 * for anything else that has to show a number before the invoice arrives.
 *
 * @method static Collection<int, MeterUsage> run(Tenant $tenant)
 */
class GetTenantUsage
{
    use AsAction;

    public function __construct(private readonly UsageCounter $counter) {}

    /**
     * @return Collection<int, MeterUsage>
     */
    public function handle(Tenant $tenant): Collection
    {
        $period = GetBillingPeriod::run($tenant);

        if (! $period instanceof BillingPeriod) {
            return new Collection;
        }

        // One read for every meter, not one per meter.
        $counts = $this->counter->all($tenant, $period->bucket());

        return GetTenantMeters::run($tenant)
            ->map(fn (MeterDefinitionData $meter): MeterUsage => new MeterUsage(
                key: $meter->key,
                event_name: $meter->event_name,
                used: $counts[$meter->key] ?? 0,
                included: $meter->included,
                period: $period,
            ))
            ->values();
    }
}
