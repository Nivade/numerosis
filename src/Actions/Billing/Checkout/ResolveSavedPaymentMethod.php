<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Actions\Billing\Checkout;

use Laravel\Cashier\Exceptions\InvalidPaymentMethod;
use Laravel\Cashier\PaymentMethod as CashierPaymentMethod;
use LogicException;
use Lorisleiva\Actions\Concerns\AsAction;
use Nvade\Numerosis\Contracts\Billing\BillableUser;
use Nvade\Numerosis\Enums\Billing\PaymentMethodType;
use Nvade\Numerosis\Exceptions\Billing\SavedPaymentMethodUnavailable;
use Stripe\PaymentMethod;

/**
 * @method static PaymentMethod run(BillableUser $billable, string $paymentMethodId)
 */
class ResolveSavedPaymentMethod
{
    use AsAction;

    public function handle(BillableUser $billable, string $paymentMethodId): PaymentMethod
    {
        $unavailable = __('numerosis::billing.checkout.saved_payment_method_unavailable');

        // Cashier's constructor for its own wrapper is the ownership check:
        // it throws InvalidPaymentMethod for another customer's payment method
        // and LogicException for one with no customer at all.
        try {
            $resolved = $billable->findPaymentMethod($paymentMethodId);
        } catch (InvalidPaymentMethod|LogicException $e) {
            throw new SavedPaymentMethodUnavailable($unavailable, 0, previous: $e);
        }

        throw_unless($resolved instanceof CashierPaymentMethod, SavedPaymentMethodUnavailable::class, $unavailable);

        $paymentMethod = $resolved->asStripePaymentMethod();

        throw_unless(PaymentMethodType::tryFrom($paymentMethod->type)?->isReusable() ?? false, SavedPaymentMethodUnavailable::class, $unavailable);

        return $paymentMethod;
    }
}
