<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Actions\Billing;

use Laravel\Cashier\Cashier;
use Lorisleiva\Actions\Concerns\AsAction;
use Nvade\Numerosis\Data\Billing\ReusablePaymentMethods;
use Nvade\Numerosis\Data\Billing\SavedPaymentMethodOption;
use Nvade\Numerosis\Models\Central\CentralUser;
use Stripe\Exception\ApiErrorException;
use Stripe\PaymentMethod;

/**
 * @method static ReusablePaymentMethods run(CentralUser $billable)
 */
class FetchReusablePaymentMethods
{
    use AsAction;

    public function handle(CentralUser $billable): ReusablePaymentMethods
    {
        if (! $billable->hasStripeId()) {
            return new ReusablePaymentMethods(collect());
        }

        $stripeId = $billable->stripeIdOrFail();

        try {
            $stripe = Cashier::stripe();
            $paymentMethods = $stripe->customers->allPaymentMethods(
                $stripeId,
                ['type' => 'card', 'limit' => 10],
            );
            $customer = $stripe->customers->retrieve($stripeId);
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
