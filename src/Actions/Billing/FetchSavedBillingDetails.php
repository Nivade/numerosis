<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Actions\Billing;

use Laravel\Cashier\Cashier;
use Lorisleiva\Actions\Concerns\AsAction;
use Nvade\Numerosis\Contracts\Billing\BillableUser;
use Nvade\Numerosis\Data\Billing\SavedBillingDetails;
use Stripe\Exception\ApiErrorException;

/**
 * @method static SavedBillingDetails run(BillableUser $billable)
 */
class FetchSavedBillingDetails
{
    use AsAction;

    public function handle(BillableUser $billable): SavedBillingDetails
    {
        if (! $billable->hasStripeId()) {
            return new SavedBillingDetails;
        }

        try {
            $customer = Cashier::stripe()->customers->retrieve(
                $billable->stripeIdOrFail(),
                ['expand' => ['tax_ids']],
            );
        } catch (ApiErrorException $e) {
            report($e);

            return new SavedBillingDetails(fetchFailed: true);
        }

        $address = $customer->address;
        $taxId = $customer->tax_ids?->data[0] ?? null;

        return new SavedBillingDetails(
            line1: $address?->line1,
            line2: $address?->line2,
            city: $address?->city,
            state: $address?->state,
            postalCode: $address?->postal_code,
            country: $address?->country,
            name: $customer->name,
            vatNumber: $taxId?->value,
        );
    }
}
