<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Actions\Billing;

use Lorisleiva\Actions\Concerns\AsAction;
use Nvade\Numerosis\Enums\Billing\TaxIdType;
use Nvade\Numerosis\Exceptions\Billing\BillingAddressRequired;
use Nvade\Numerosis\Exceptions\Billing\BillingAddressUnavailable;
use Nvade\Numerosis\Exceptions\Billing\InvalidVatNumber;
use Nvade\Numerosis\Models\Central\Tenant;
use Stripe\Exception\ApiErrorException;

/**
 * Attaches a VAT number to a tenant's Stripe customer after signup, deriving
 * the tax-id type from the billing country already on record.
 *
 * The Stripe customer is the source of truth for billing address; nothing is
 * cached locally.
 *
 * @method static void run(Tenant $tenant, string $vatNumber)
 */
class AddVatNumber
{
    use AsAction;

    public function handle(Tenant $tenant, string $vatNumber): void
    {
        if (! $tenant->hasStripeId()) {
            throw new BillingAddressRequired(__('numerosis::billing.checkout.vat_country_unsupported'));
        }

        try {
            $customer = $tenant->asStripeCustomer();
        } catch (ApiErrorException $e) {
            report($e);

            throw new BillingAddressUnavailable('Unable to verify your billing address right now. Please try again shortly.', 0, $e);
        }

        $taxIdType = TaxIdType::forCountry($customer->address->country ?? null);

        if ($taxIdType === null) {
            throw new InvalidVatNumber(__('numerosis::billing.checkout.vat_country_unsupported'));
        }

        AttachVatNumber::run($tenant->stripeIdOrFail(), $taxIdType->value, $vatNumber);
    }
}
