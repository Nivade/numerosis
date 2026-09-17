<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Actions\Billing;

use Laravel\Cashier\Cashier;
use Lorisleiva\Actions\Concerns\AsAction;
use Nvade\Numerosis\Exceptions\Billing\InvalidVatNumber;
use Stripe\Exception\ApiErrorException;

/**
 * Attaches a tax id to a Stripe customer, replacing any it already carries.
 *
 * Takes a raw Stripe customer id instead of a billable. Its callers
 * ({@see SyncBillingAddress}, {@see AddVatNumber}) hold a `CentralUser` and a
 * `Tenant`, and Cashier ships no interface for "has the Billable trait" that
 * both satisfy.
 *
 * @method static void run(string $stripeId, string $taxIdType, string $vatNumber)
 */
class AttachVatNumber
{
    use AsAction;

    public function handle(string $stripeId, string $taxIdType, string $vatNumber): void
    {
        try {
            Cashier::stripe()->customers->createTaxId($stripeId, [
                'type' => $taxIdType,
                'value' => $vatNumber,
            ]);
        } catch (ApiErrorException $e) {
            throw new InvalidVatNumber(__('numerosis::billing.checkout.invalid_vat_number'), 0, previous: $e);
        }
    }
}
