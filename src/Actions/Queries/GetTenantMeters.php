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
 * The meters a plan bills through, read from `metadata.options.meters`. A
 * tenant with no active subscription has no price to report against, so it
 * meters nothing.
 *
 * @method static Collection<int, MeterDefinitionData> run(Tenant|Plan|null $subject)
 */
class GetTenantMeters
{
    use AsAction;

    /**
     * @return Collection<int, MeterDefinitionData>
     */
    public function handle(Tenant|Plan|null $subject): Collection
    {
        $plan = $subject instanceof Tenant
            ? $this->activePlan($subject)
            : $subject;

        $declared = $plan?->metadata()['options']['meters'] ?? null;

        if (! is_array($declared)) {
            return new Collection;
        }

        return new Collection($declared)
            ->map(fn (mixed $entry): ?MeterDefinitionData => is_array($entry) ? MeterDefinitionData::tryFrom($entry) : null)
            ->filter()
            ->values();
    }

    private function activePlan(Tenant $tenant): ?Plan
    {
        $subscription = GetActiveSubscription::run($tenant);

        return $subscription instanceof Subscription ? $subscription->paymentPlan : null;
    }
}
