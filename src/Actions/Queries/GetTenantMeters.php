<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Actions\Queries;

use Illuminate\Support\Collection;
use Lorisleiva\Actions\Concerns\AsAction;
use Nvade\Numerosis\Contracts\Billing\Plan;
use Nvade\Numerosis\Data\Billing\MeterDefinitionData;
use Nvade\Numerosis\Models\Central\Subscription;
use Nvade\Numerosis\Models\Central\Tenant;

/**
 * The meters the tenant's current plan bills through, read from
 * `metadata.options.meters`. A tenant with no active subscription meters
 * nothing: there is no price to report against.
 *
 * @method static Collection<int, MeterDefinitionData> run(Tenant $tenant)
 */
class GetTenantMeters
{
    use AsAction;

    /**
     * @return Collection<int, MeterDefinitionData>
     */
    public function handle(Tenant $tenant): Collection
    {
        $subscription = GetActiveSubscription::run($tenant);

        return self::forPlan($subscription instanceof Subscription ? $subscription->paymentPlan : null);
    }

    /**
     * @return Collection<int, MeterDefinitionData>
     */
    public static function forPlan(?Plan $plan): Collection
    {
        $declared = $plan?->metadata()['options']['meters'] ?? null;

        if (! is_array($declared)) {
            return new Collection;
        }

        return new Collection($declared)
            ->map(fn (mixed $entry): ?MeterDefinitionData => is_array($entry) ? MeterDefinitionData::tryFrom($entry) : null)
            ->filter()
            ->values();
    }
}
