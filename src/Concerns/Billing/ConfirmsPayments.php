<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Concerns\Billing;

use Laravel\Cashier\Exceptions\IncompletePayment;
use Nvade\Numerosis\Models\Central\Subscription;
use Stripe\Exception\ApiErrorException;

/**
 * The protocol every surface follows where a subscription can be challenged
 * with 3DS.
 *
 * Compose it into a component that creates subscriptions, and implement the
 * two hooks below.
 */
trait ConfirmsPayments
{
    public ?string $paymentError = null;

    /**
     * Called when CreateInlineSubscription (or an equivalent action) catches
     * IncompletePayment: the SetupIntent was confirmed, but the first
     * invoice still needs a 3DS challenge Stripe could not resolve upfront.
     */
    protected function handleIncompletePayment(IncompletePayment $e): void
    {
        $this->dispatch('requires-action', clientSecret: $e->payment->clientSecret());
    }

    /**
     * Called once the challenge is resolved in the browser.
     *
     * Asks Stripe rather than reading the local subscription row, which is
     * still `incomplete` until the webhook lands. Judge the result by an
     * allowlist of settled statuses — a denylist provisions an unpaid tenant
     * for every state nobody thought to exclude.
     */
    protected function hasSettled(Subscription $subscription): bool
    {
        try {
            $subscription->syncStripeStatus();
        } catch (ApiErrorException $e) {
            report($e);

            return false;
        }

        return in_array($subscription->stripe_status, ['active', 'trialing'], true);
    }
}
