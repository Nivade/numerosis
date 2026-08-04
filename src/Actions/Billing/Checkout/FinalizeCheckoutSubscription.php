<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Actions\Billing\Checkout;

use Nvade\Numerosis\Models\Central\CentralUser;
use Nvade\Numerosis\Models\Central\PendingTenantProvision;
use Laravel\Cashier\Exceptions\IncompletePayment;
use Laravel\Cashier\Subscription;
use Lorisleiva\Actions\Concerns\AsAction;
use Stripe\PaymentMethod;

/**
 * See .claude/rules/billing-checkout.md.
 *
 * @method static Subscription run(PendingTenantProvision $pending, PaymentMethod $paymentMethod, ?CentralUser $billable)
 */
class FinalizeCheckoutSubscription
{
    use AsAction;

    /**
     * @throws IncompletePayment
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
