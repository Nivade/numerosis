<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Actions\Billing;

use Laravel\Cashier\PaymentMethod;
use Lorisleiva\Actions\Concerns\AsAction;
use Nvade\Numerosis\Contracts\Billing\BillableUser;
use Nvade\Numerosis\Data\Billing\ReusablePaymentMethods;
use Nvade\Numerosis\Data\Billing\SavedPaymentMethodOption;
use Stripe\Customer;
use Stripe\Exception\ApiErrorException;

/**
 * Takes an already-retrieved customer when the caller has one, so the checkout
 * screen pays for a single Stripe round trip rather than one per reader.
 *
 * @method static ReusablePaymentMethods run(BillableUser $billable, ?Customer $customer = null)
 */
class FetchReusablePaymentMethods
{
    use AsAction;

    public function handle(BillableUser $billable, ?Customer $customer = null): ReusablePaymentMethods
    {
        if (! $billable->hasStripeId()) {
            return new ReusablePaymentMethods(collect());
        }

        $customer ??= FetchStripeCustomer::run($billable);

        if ($customer === null) {
            return new ReusablePaymentMethods(collect(), fetchFailed: true);
        }

        try {
            $paymentMethods = $billable->paymentMethods('card', ['limit' => 10]);
        } catch (ApiErrorException $e) {
            report($e);

            return new ReusablePaymentMethods(collect(), fetchFailed: true);
        }

        $defaultId = $customer->invoice_settings->default_payment_method ?? null;

        $options = $paymentMethods->map(function (PaymentMethod $pm) use ($defaultId): SavedPaymentMethodOption {
            $stripePaymentMethod = $pm->asStripePaymentMethod();

            return new SavedPaymentMethodOption(
                id: $stripePaymentMethod->id,
                brand: $stripePaymentMethod->card->brand ?? 'card',
                last4: $stripePaymentMethod->card->last4 ?? '',
                expMonth: $stripePaymentMethod->card->exp_month ?? 0,
                expYear: $stripePaymentMethod->card->exp_year ?? 0,
                isDefault: $stripePaymentMethod->id === $defaultId,
            );
        });

        return new ReusablePaymentMethods($options);
    }
}
