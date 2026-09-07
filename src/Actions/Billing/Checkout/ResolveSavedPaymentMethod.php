<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Actions\Billing\Checkout;

use Laravel\Cashier\Cashier;
use Lorisleiva\Actions\Concerns\AsAction;
use Nvade\Numerosis\Contracts\Billing\BillableUser;
use Nvade\Numerosis\Exceptions\Billing\SavedPaymentMethodUnavailable;
use Stripe\Exception\ApiErrorException;
use Stripe\PaymentMethod;

/**
 * @method static PaymentMethod run(BillableUser $billable, string $paymentMethodId)
 */
class ResolveSavedPaymentMethod
{
    use AsAction;

    private const array REUSABLE_TYPES = ['card'];

    public function handle(BillableUser $billable, string $paymentMethodId): PaymentMethod
    {
        try {
            $paymentMethod = Cashier::stripe()->paymentMethods->retrieve($paymentMethodId);
        } catch (ApiErrorException $e) {
            report($e);

            throw new SavedPaymentMethodUnavailable(__('numerosis::billing.checkout.saved_payment_method_unavailable'), $e->getCode(), previous: $e);
        }

        $customerId = is_string($paymentMethod->customer) ? $paymentMethod->customer : $paymentMethod->customer?->id;

        $isOwnedAndReusable = $billable->hasStripeId()
            && $customerId === $billable->stripeId()
            && in_array($paymentMethod->type, self::REUSABLE_TYPES, true);

        throw_unless($isOwnedAndReusable, SavedPaymentMethodUnavailable::class, __('numerosis::billing.checkout.saved_payment_method_unavailable'));

        return $paymentMethod;
    }
}
