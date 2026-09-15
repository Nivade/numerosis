<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Actions\Billing;

use Laravel\Cashier\PaymentMethod;
use Lorisleiva\Actions\Concerns\AsAction;
use Nvade\Numerosis\Contracts\Billing\BillableUser;
use Nvade\Numerosis\Data\Billing\ReusablePaymentMethods;
use Nvade\Numerosis\Data\Billing\SavedPaymentMethodOption;
use Nvade\Numerosis\Enums\Billing\PaymentMethodType;
use Nvade\Numerosis\Enums\FetchState;
use Stripe\Customer;
use Stripe\Exception\ApiErrorException;

/**
 * The saved cards a billable may pay with again, and which one is default.
 *
 * Takes an already-retrieved customer when the caller has one, so the checkout
 * screen pays for a single Stripe round trip rather than one per reader. A
 * customer belonging to somebody else is ignored, not read.
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

        if ($customer instanceof Customer && $customer->id !== $billable->stripeId()) {
            $customer = null;
        }

        $customer ??= FetchStripeCustomer::run($billable);

        if ($customer === null) {
            return new ReusablePaymentMethods(collect(), fetchState: FetchState::Failed);
        }

        try {
            $paymentMethods = $billable->paymentMethods(PaymentMethodType::Card->value, ['limit' => 10]);
        } catch (ApiErrorException $e) {
            report($e);

            return new ReusablePaymentMethods(collect(), fetchState: FetchState::Failed);
        }

        $defaultId = $customer->invoice_settings->default_payment_method ?? null;

        $options = $paymentMethods->map(function (PaymentMethod $pm) use ($defaultId): SavedPaymentMethodOption {
            $stripePaymentMethod = $pm->asStripePaymentMethod();

            return new SavedPaymentMethodOption(
                id: $stripePaymentMethod->id,
                brand: $stripePaymentMethod->card->brand ?? null,
                last4: $stripePaymentMethod->card->last4 ?? '',
                expMonth: $stripePaymentMethod->card->exp_month ?? 0,
                expYear: $stripePaymentMethod->card->exp_year ?? 0,
                isDefault: $stripePaymentMethod->id === $defaultId,
            );
        });

        return new ReusablePaymentMethods($options, fetchState: FetchState::Loaded);
    }
}
