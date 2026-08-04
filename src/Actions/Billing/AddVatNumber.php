<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Actions\Billing;

use Nvade\Numerosis\Exceptions\Billing\BillingAddressRequired;
use Nvade\Numerosis\Exceptions\Billing\BillingAddressUnavailable;
use Nvade\Numerosis\Exceptions\Billing\InvalidVatNumber;
use Nvade\Numerosis\Models\Central\Tenant;
use Nvade\Numerosis\Support\Billing\TaxIdType;
use Laravel\Cashier\Cashier;
use Lorisleiva\Actions\Concerns\AsAction;
use Stripe\Exception\ApiErrorException;

/**
 * See .claude/rules/billing-checkout.md.
 *
 * @method static void run(Tenant $tenant, string $vatNumber)
 */
class AddVatNumber
{
    use AsAction;

    public function handle(Tenant $tenant, string $vatNumber): void
    {
        if (! $tenant->hasStripeId()) {
            throw new BillingAddressRequired(__('billing.checkout.vat_country_unsupported'));
        }

        try {
            $customer = Cashier::stripe()->customers->retrieve($tenant->stripeIdOrFail());
        } catch (ApiErrorException $e) {
            report($e);

            throw new BillingAddressUnavailable('Unable to verify your billing address right now. Please try again shortly.', $e->getCode(), $e);
        }

        $taxIdType = TaxIdType::forCountry($customer->address->country ?? null);

        if ($taxIdType === null) {
            throw new InvalidVatNumber(__('billing.checkout.vat_country_unsupported'));
        }

        AttachVatNumber::run($tenant->stripeIdOrFail(), $taxIdType, $vatNumber);
    }
}
