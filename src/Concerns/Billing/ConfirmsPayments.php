<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Concerns\Billing;

use Laravel\Cashier\Exceptions\IncompletePayment;
use Laravel\Cashier\Subscription;
use Stripe\Exception\ApiErrorException;

/**
 * The payment protocol shared by every place a subscription can be
 * challenged with 3DS: Nvade\Numerosis\Livewire\Billing\Checkout (reached both standalone
 * at /checkout/{domain} and embedded by the registration wizard's Payment
 * step) and the module-purchase pages via Nvade\Numerosis\Concerns\Modules\PurchasesModules.
 *
 * Originally extracted from Nvade\Numerosis\Livewire\Tenant\Registration\Steps\Payment
 * when that step owned its own subscribe()/confirmed()/settle(). It no longer
 * does — Payment now only resolves the reserved domain and embeds Checkout,
 * so Checkout is the single implementation of this protocol for registration.
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
     * Stripe is asked, not the local row: the challenge was resolved in the
     * browser moments ago and customer.subscription.updated has not landed
     * yet, so stripe_status is still the `incomplete` the subscription was
     * created with.
     *
     * An allowlist, not a denylist — `incomplete_expired` is ~23h away, so a
     * denylist would provision an unpaid tenant for every other unsettled
     * state. See .claude/rules/billing-checkout.md.
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
