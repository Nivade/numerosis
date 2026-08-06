<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Actions\Billing\Checkout;

use Lorisleiva\Actions\Concerns\AsAction;
use Nvade\Numerosis\Exceptions\Billing\CheckoutAlreadyCompleted;
use Nvade\Numerosis\Models\Central\CentralUser;
use Nvade\Numerosis\Models\Central\PendingTenantProvision;

/**
 * @method static void run(PendingTenantProvision $pending, CentralUser $billable)
 */
class AssertPendingReservationIsFresh
{
    use AsAction;

    public function handle(PendingTenantProvision $pending, CentralUser $billable): void
    {
        if ($pending->stripe_subscription_id === null) {
            return;
        }

        $subscription = $billable->subscriptions()->where('stripe_id', $pending->stripe_subscription_id)->first();

        if (! $subscription || $subscription->stripe_status !== 'canceled') {
            throw new CheckoutAlreadyCompleted(__('numerosis::billing.checkout.already_subscribed'));
        }
    }
}
