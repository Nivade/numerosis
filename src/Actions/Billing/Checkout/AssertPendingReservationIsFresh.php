<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Actions\Billing\Checkout;

use Lorisleiva\Actions\Concerns\AsAction;
use Nvade\Numerosis\Contracts\Billing\BillableUser;
use Nvade\Numerosis\Enums\Billing\SubscriptionStatus;
use Nvade\Numerosis\Exceptions\Billing\CheckoutAlreadyCompleted;
use Nvade\Numerosis\Models\Central\TenantProvision;

/**
 * @method static void run(TenantProvision $pending, BillableUser $billable)
 */
class AssertPendingReservationIsFresh
{
    use AsAction;

    public function handle(TenantProvision $pending, BillableUser $billable): void
    {
        if ($pending->stripe_subscription_id === null) {
            return;
        }

        $subscription = $billable->subscriptions()->where('stripe_id', $pending->stripe_subscription_id)->first();

        if ($subscription === null || $subscription->status() !== SubscriptionStatus::Canceled) {
            throw new CheckoutAlreadyCompleted(__('numerosis::billing.checkout.already_subscribed'));
        }
    }
}
