<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Actions\Billing;

use Laravel\Cashier\Cashier;
use Lorisleiva\Actions\Concerns\AsAction;
use Nvade\Numerosis\Contracts\Billing\BillableUser;
use Nvade\Numerosis\Data\Billing\ReusablePaymentMethods;
use Nvade\Numerosis\Data\Billing\SavedPaymentMethodOption;
use Stripe\Customer;
use Stripe\Exception\ApiErrorException;
use Stripe\PaymentMethod;

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
            $paymentMethods = Cashier::stripe()->customers->allPaymentMethods(
                $billable->stripeIdOrFail(),
                ['type' => 'card', 'limit' => 10],
            );
        } catch (ApiErrorException $e) {
            report($e);

            return new ReusablePaymentMethods(collect(), fetchFailed: true);
        }

        $defaultId = $customer->invoice_settings->default_payment_method ?? null;

        $options = collect($paymentMethods->data)->map(fn (PaymentMethod $pm) => new SavedPaymentMethodOption(
            id: $pm->id,
            brand: $pm->card->brand ?? 'card',
            last4: $pm->card->last4 ?? '',
            expMonth: $pm->card->exp_month ?? 0,
            expYear: $pm->card->exp_year ?? 0,
            isDefault: $pm->id === $defaultId,
        ));

        return new ReusablePaymentMethods($options);
    }
}
