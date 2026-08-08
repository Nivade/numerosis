<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Actions\Billing\Checkout;

use Laravel\Cashier\Cashier;
use Lorisleiva\Actions\Concerns\AsAction;
use Nvade\Numerosis\Exceptions\Billing\SavedPaymentMethodUnavailable;
use Nvade\Numerosis\Models\Central\CentralUser;
use Stripe\Exception\ApiErrorException;
use Stripe\PaymentMethod;

/**
 * @method static PaymentMethod run(CentralUser $billable, string $paymentMethodId)
 */
class ResolveSavedPaymentMethod
{
    use AsAction;

    private const array REUSABLE_TYPES = ['card'];

    public function handle(CentralUser $billable, string $paymentMethodId): PaymentMethod
    {
        try {
            $paymentMethod = Cashier::stripe()->paymentMethods->retrieve($paymentMethodId);
        } catch (ApiErrorException $e) {
            report($e);

            throw new SavedPaymentMethodUnavailable(__('numerosis::billing.checkout.saved_payment_method_unavailable'), $e->getCode(), previous: $e);
        }

        $customerId = is_string($paymentMethod->customer) ? $paymentMethod->customer : $paymentMethod->customer?->id;

        if (! $billable->hasStripeId() || $customerId !== $billable->stripe_id) {
            throw new SavedPaymentMethodUnavailable(__('numerosis::billing.checkout.saved_payment_method_unavailable'));
        }

        if (! in_array($paymentMethod->type, self::REUSABLE_TYPES, true)) {
            throw new SavedPaymentMethodUnavailable(__('numerosis::billing.checkout.saved_payment_method_unavailable'));
        }

        return $paymentMethod;
    }
}
