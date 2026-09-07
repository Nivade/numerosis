<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Actions\Billing;

use Lorisleiva\Actions\Concerns\AsAction;
use Nvade\Numerosis\Contracts\Billing\BillableUser;
use Nvade\Numerosis\Data\Billing\SavedBillingDetails;
use Stripe\Customer;

/**
 * Takes an already-retrieved customer when the caller has one, so the checkout
 * screen pays for a single Stripe round trip rather than one per reader.
 *
 * @method static SavedBillingDetails run(BillableUser $billable, ?Customer $customer = null)
 */
class FetchSavedBillingDetails
{
    use AsAction;

    public function handle(BillableUser $billable, ?Customer $customer = null): SavedBillingDetails
    {
        if (! $billable->hasStripeId()) {
            return new SavedBillingDetails;
        }

        $customer ??= FetchStripeCustomer::run($billable);

        if ($customer === null) {
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
