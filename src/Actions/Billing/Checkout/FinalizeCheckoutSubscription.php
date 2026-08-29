<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Actions\Billing\Checkout;

use Laravel\Cashier\Exceptions\IncompletePayment;
use Laravel\Cashier\Subscription;
use Lorisleiva\Actions\Concerns\AsAction;
use Nvade\Numerosis\Models\Central\CentralUser;
use Nvade\Numerosis\Models\Central\PendingTenantProvision;
use Stripe\Exception\ApiErrorException;
use Stripe\PaymentMethod;

/**
 * Creates the subscription and settles the checkout, in that order.
 *
 * Shared by the redirect return route and the Stripe webhook, so both finish
 * a checkout identically. Callers decide for themselves what an
 * `IncompletePayment` means in their context.
 *
 * @method static Subscription run(PendingTenantProvision $pending, PaymentMethod $paymentMethod, ?CentralUser $billable)
 */
class FinalizeCheckoutSubscription
{
    use AsAction;

    /**
     * @throws IncompletePayment
     * @throws ApiErrorException propagated from {@see CreateInlineSubscription}
     */
    public function handle(PendingTenantProvision $pending, PaymentMethod $paymentMethod, ?CentralUser $billable): Subscription
    {
        $subscription = CreateInlineSubscription::run($pending, $paymentMethod->id, $billable);

        $stripeCustomerId = $billable?->stripe_id;
        $userId = $billable instanceof CentralUser ? (string) $billable->id : null;

        SettleCheckout::run($pending, $subscription, $stripeCustomerId, $userId);

        return $subscription;
    }
}
