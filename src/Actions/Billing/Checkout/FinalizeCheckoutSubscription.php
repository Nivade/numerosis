<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Actions\Billing\Checkout;

use Laravel\Cashier\Exceptions\IncompletePayment;
use Lorisleiva\Actions\Concerns\AsAction;
use Nvade\Numerosis\Contracts\Billing\BillableUser;
use Nvade\Numerosis\Models\Central\PendingTenantProvision;
use Nvade\Numerosis\Models\Central\Subscription;
use Stripe\Exception\ApiErrorException;
use Stripe\PaymentMethod;

/**
 * Creates the subscription and settles the checkout, in that order.
 *
 * Shared by the redirect return route and the Stripe webhook, so both finish
 * a checkout identically. Callers decide for themselves what an
 * `IncompletePayment` means in their context.
 *
 * @method static Subscription run(PendingTenantProvision $pending, PaymentMethod $paymentMethod, ?BillableUser $billable)
 */
class FinalizeCheckoutSubscription
{
    use AsAction;

    /**
     * @throws IncompletePayment
     * @throws ApiErrorException propagated from {@see CreateInlineSubscription}
     */
    public function handle(PendingTenantProvision $pending, PaymentMethod $paymentMethod, ?BillableUser $billable): Subscription
    {
        $subscription = CreateInlineSubscription::run($pending, $paymentMethod->id, $billable);

        $stripeCustomerId = $billable?->stripeId();
        $userId = $billable === null ? null : (string) $billable->getKey();

        SettleCheckout::run($pending, $subscription, $stripeCustomerId, $userId);

        return $subscription;
    }
}
