<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Actions\Queries;

use Illuminate\Support\Carbon;
use Laravel\Cashier\SubscriptionItem as CashierSubscriptionItem;
use Lorisleiva\Actions\Concerns\AsAction;
use Nvade\Numerosis\Data\Billing\BillingPeriod;
use Nvade\Numerosis\Models\Central\Subscription;
use Nvade\Numerosis\Models\Central\SubscriptionItem;
use Nvade\Numerosis\Models\Central\Tenant;

/**
 * The period usage is counted and billed against. Read from the subscription
 * item Stripe stamped it on, never from the calendar: an annual plan bought on
 * the 9th bills the 9th to the 9th, and bucketing it by calendar month
 * mis-bills every one of them.
 *
 * @method static BillingPeriod|null run(Tenant $tenant)
 */
class GetBillingPeriod
{
    use AsAction;

    public function handle(Tenant $tenant): ?BillingPeriod
    {
        $subscription = GetActiveSubscription::run($tenant);

        if (! $subscription instanceof Subscription) {
            return null;
        }

        return self::forSubscription($subscription);
    }

    /** Null when Stripe stamped no period on any item: nothing is recorded locally to report against. */
    public static function forSubscription(Subscription $subscription): ?BillingPeriod
    {
        $stamped = $subscription->items
            ->filter(fn (CashierSubscriptionItem $item): bool => $item instanceof SubscriptionItem
                && $item->current_period_start instanceof Carbon
                && $item->current_period_end instanceof Carbon)
            // A metered item's period is the one a meter is reported against;
            // a licensed sibling may sit on a different anchor.
            ->sortByDesc(fn (CashierSubscriptionItem $item): bool => $item instanceof SubscriptionItem && $item->isMetered())
            ->first();

        if (! $stamped instanceof SubscriptionItem
            || ! $stamped->current_period_start instanceof Carbon
            || ! $stamped->current_period_end instanceof Carbon) {
            return null;
        }

        return new BillingPeriod(
            start: $stamped->current_period_start->copy(),
            end: $stamped->current_period_end->copy(),
        );
    }
}
