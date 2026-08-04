<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Actions\Billing;

use Nvade\Numerosis\Data\Billing\SavedPaymentMethodOption;
use Nvade\Numerosis\Models\Central\CentralUser;
use Illuminate\Support\Collection;
use Laravel\Cashier\Cashier;
use Lorisleiva\Actions\Concerns\AsAction;
use Stripe\Exception\ApiErrorException;
use Stripe\PaymentMethod;

/**
 * @method static FetchReusablePaymentMethodsResult run(CentralUser $billable)
 */
class FetchReusablePaymentMethods
{
    use AsAction;

    public function handle(CentralUser $billable): FetchReusablePaymentMethodsResult
    {
        if (! $billable->hasStripeId()) {
            return new FetchReusablePaymentMethodsResult(collect());
        }

        try {
            $stripe = Cashier::stripe();
            $paymentMethods = $stripe->customers->allPaymentMethods(
                $billable->stripeIdOrFail(),
                ['type' => 'card', 'limit' => 10],
            );
            $customer = $stripe->customers->retrieve($billable->stripeIdOrFail());
        } catch (ApiErrorException $e) {
            report($e);

            return new FetchReusablePaymentMethodsResult(collect(), fetchFailed: true);
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

        return new FetchReusablePaymentMethodsResult($options);
    }
}

class FetchReusablePaymentMethodsResult
{
    /**
     * @param  Collection<int, SavedPaymentMethodOption>  $options
     */
    public function __construct(
        public Collection $options,
        public bool $fetchFailed = false,
    ) {}
}
