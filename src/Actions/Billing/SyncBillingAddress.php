<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Actions\Billing;

use Nvade\Numerosis\Exceptions\Billing\InvalidVatNumber;
use Nvade\Numerosis\Models\Central\CentralUser;
use Nvade\Numerosis\Support\Billing\TaxIdType;
use Laravel\Cashier\Cashier;
use Lorisleiva\Actions\Concerns\AsAction;
use Stripe\PaymentMethod;

/**
 * See .claude/rules/billing-checkout.md.
 *
 * @method static void run(CentralUser $billable, PaymentMethod $paymentMethod, ?string $vatNumber = null)
 */
class SyncBillingAddress
{
    use AsAction;

    public function handle(CentralUser $billable, PaymentMethod $paymentMethod, ?string $vatNumber = null): void
    {
        $address = $paymentMethod->billing_details->address ?? null;
        $country = $address?->country;

        Cashier::stripe()->customers->update($billable->stripeIdOrFail(), [
            'address' => array_filter([
                'line1' => $address?->line1,
                'line2' => $address?->line2,
                'city' => $address?->city,
                'state' => $address?->state,
                'postal_code' => $address?->postal_code,
                'country' => $country,
            ], static fn (?string $value): bool => $value !== null),
        ]);

        if ($vatNumber === null || $vatNumber === '') {
            return;
        }

        $taxIdType = TaxIdType::forCountry($country);

        if ($taxIdType === null) {
            throw new InvalidVatNumber(__('billing.checkout.vat_country_unsupported'));
        }

        AttachVatNumber::run($billable->stripeIdOrFail(), $taxIdType, $vatNumber);
    }
}
