<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Actions\Billing;

use Lorisleiva\Actions\Concerns\AsAction;
use Nvade\Numerosis\Contracts\Billing\BillableUser;
use Nvade\Numerosis\Data\Billing\SavedBillingDetails;
use Nvade\Numerosis\Enums\FetchState;
use Stripe\Customer;

/**
 * The address, name and VAT number Stripe already holds for a billable.
 *
 * Takes an already-retrieved customer when the caller has one, so the checkout
 * screen pays for a single Stripe round trip rather than one per reader. A
 * customer belonging to somebody else is ignored, not read.
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

        if ($customer instanceof Customer && $customer->id !== $billable->stripeId()) {
            $customer = null;
        }

        $customer ??= FetchStripeCustomer::run($billable);

        if ($customer === null) {
            return new SavedBillingDetails(fetchState: FetchState::Failed);
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
            fetchState: FetchState::Loaded,
        );
    }
}
