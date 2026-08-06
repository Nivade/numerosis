<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Actions\Billing\Checkout;

use Laravel\Cashier\Cashier;
use Lorisleiva\Actions\Concerns\AsAction;
use Nvade\Numerosis\Contracts\Billing\BillableResolver;
use Nvade\Numerosis\Exceptions\Billing\CheckoutSessionExpired;
use Nvade\Numerosis\Exceptions\Billing\SetupIntentNotConfirmed;
use Nvade\Numerosis\Models\Central\CentralUser;
use Nvade\Numerosis\Models\Central\PendingTenantProvision;
use Nvade\Numerosis\Services\Billing\Checkout\ResolvedSetupIntent;
use Nvade\Numerosis\Support\Numerosis;
use Stripe\Exception\ApiErrorException;
use Stripe\PaymentMethod;

/**
 * See .claude/rules/billing-checkout.md.
 *
 * @method static ResolvedSetupIntent run(string $setupIntentId)
 */
class ResolveSetupIntent
{
    use AsAction;

    public function __construct(private readonly BillableResolver $billables) {}

    public function handle(string $setupIntentId): ResolvedSetupIntent
    {
        /** @var PendingTenantProvision|null $pending */
        $pending = Numerosis::model(PendingTenantProvision::class)::where('stripe_setup_intent_id', $setupIntentId)->first();

        if (! $pending) {
            throw new CheckoutSessionExpired(__('numerosis::billing.checkout.session_expired'));
        }

        $billable = $this->billables->resolve();

        if (! $billable instanceof CentralUser || $billable->global_id !== $pending->global_id) {
            throw new CheckoutSessionExpired(__('numerosis::billing.checkout.foreign_session'));
        }

        AssertPendingReservationIsFresh::run($pending, $billable);

        try {
            $setupIntent = Cashier::stripe()->setupIntents->retrieve($setupIntentId, [
                'expand' => ['payment_method'],
            ]);
        } catch (ApiErrorException) {
            throw new CheckoutSessionExpired(__('numerosis::billing.checkout.session_expired'));
        }

        $customerId = is_string($setupIntent->customer) ? $setupIntent->customer : $setupIntent->customer?->id;

        if (! $billable->hasStripeId() || $customerId !== $billable->stripe_id) {
            throw new CheckoutSessionExpired(__('numerosis::billing.checkout.foreign_session'));
        }

        $rawPaymentMethod = $setupIntent->payment_method instanceof PaymentMethod ? $setupIntent->payment_method : null;

        if ($setupIntent->status !== 'succeeded' || ! $rawPaymentMethod instanceof PaymentMethod) {
            throw new SetupIntentNotConfirmed(__('numerosis::billing.checkout.confirmation_failed'));
        }

        $paymentMethod = ResolveAttachedPaymentMethod::run($setupIntent) ?? $rawPaymentMethod;

        return new ResolvedSetupIntent($pending, $paymentMethod);
    }
}
