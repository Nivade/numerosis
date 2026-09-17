<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Actions\Billing\Usage;

use Illuminate\Support\Collection;
use Lorisleiva\Actions\Concerns\AsAction;
use Nvade\Numerosis\Actions\Queries\GetTenantMeters;
use Nvade\Numerosis\Data\Billing\MeterDefinitionData;
use Nvade\Numerosis\Models\Central\Subscription;
use Nvade\Numerosis\Models\Central\SubscriptionItem;

/**
 * Writes `meter_id` and `meter_event_name` onto the items of a metered
 * subscription. Stripe carries the meter id on a usage-based price, but the
 * event name is the plan's own declaration, so the two meet here rather than
 * in the data object that reads Stripe.
 *
 * Idempotent: both writers of a subscription row reach this, and a second call
 * writes the same values.
 *
 * @method static int run(Subscription $subscription)
 */
class StampSubscriptionMeters
{
    use AsAction;

    /** @return int How many items were stamped. */
    public function handle(Subscription $subscription): int
    {
        $meters = GetTenantMeters::forPlan($subscription->paymentPlan);

        if ($meters->isEmpty()) {
            return 0;
        }

        $stamped = 0;

        foreach ($subscription->items as $item) {
            if (! $item instanceof SubscriptionItem) {
                continue;
            }

            $meter = $this->meterFor($meters, $item);

            if (! $meter instanceof MeterDefinitionData) {
                continue;
            }

            $item->update([
                'meter_event_name' => $meter->event_name,
                'meter_id' => $item->meter_id ?? $meter->meter_id,
            ]);

            $stamped++;
        }

        return $stamped;
    }

    /**
     * Matched by price where the plan names one, and by the meter Stripe
     * stamped on the price otherwise — a plan with a single meter and no price
     * declared is the common case.
     *
     * @param  Collection<int, MeterDefinitionData>  $meters
     */
    private function meterFor(Collection $meters, SubscriptionItem $item): ?MeterDefinitionData
    {
        return $meters->first(fn (MeterDefinitionData $meter): bool => $meter->price === $item->stripe_price)
            ?? $meters->first(fn (MeterDefinitionData $meter): bool => $meter->meter_id !== null
                && $meter->meter_id === $item->meter_id)
            ?? ($item->meter_id !== null && $meters->count() === 1 ? $meters->first() : null);
    }
}
