<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Actions\Billing;

use Nvade\Numerosis\Exceptions\Billing\InvalidVatNumber;
use Laravel\Cashier\Cashier;
use Lorisleiva\Actions\Concerns\AsAction;
use Stripe\Exception\ApiErrorException;

/**
 * See .claude/rules/billing-checkout.md.
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
            throw new InvalidVatNumber(__('billing.checkout.invalid_vat_number'), $e->getCode(), previous: $e);
        }
    }
}
