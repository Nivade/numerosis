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
        // Cashier's constructor for its own wrapper is the ownership check:
        // it throws InvalidPaymentMethod when the resolved payment method's
        // customer isn't $billable's, and a plain LogicException when it has
        // no customer at all. Both mean "not this billable's", same as a
        // missing/undeliverable id (findPaymentMethod() returns null then).
        try {
            $resolved = $billable->findPaymentMethod($paymentMethodId);
        } catch (InvalidPaymentMethod|LogicException $e) {
            throw new SavedPaymentMethodUnavailable(__('numerosis::billing.checkout.saved_payment_method_unavailable'), 0, previous: $e);
        }

        throw_unless($resolved instanceof CashierPaymentMethod, SavedPaymentMethodUnavailable::class, __('numerosis::billing.checkout.saved_payment_method_unavailable'));

        $paymentMethod = $resolved->asStripePaymentMethod();

        throw_unless(PaymentMethodType::tryFrom($paymentMethod->type)?->isReusable() ?? false, SavedPaymentMethodUnavailable::class, __('numerosis::billing.checkout.saved_payment_method_unavailable'));

        return $paymentMethod;
    }
}
