<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Data\Billing;

use Spatie\LaravelData\Data;

/**
 * The metered part of an invoice, in minor units. A usage line carries no
 * amount until Stripe closes the period, so the total on a metered invoice is
 * only knowable from the invoice itself.
 */
final class MeteredInvoiceAmount extends Data
{
    public function __construct(
        public ?int $amount,
    ) {}

    /**
     * @param  array<array-key, mixed>  $invoice
     */
    public static function fromInvoice(array $invoice): self
    {
        $lines = $invoice['lines'] ?? null;
        $lines = is_array($lines) ? ($lines['data'] ?? null) : null;

        if (! is_array($lines)) {
            return new self(null);
        }

        $total = 0;
        $metered = false;

        foreach ($lines as $line) {
            if (! is_array($line)) {
                continue;
            }

            $pricing = $line['pricing'] ?? [];
            $price = is_array($pricing) ? ($pricing['price_details'] ?? []) : [];
            $plan = $line['plan'] ?? [];
            $isUsage = (is_array($plan) ? ($plan['usage_type'] ?? null) : null) === 'metered'
                || (is_array($price) && isset($price['meter']));

            if (! $isUsage) {
                continue;
            }

            $metered = true;
            $amount = $line['amount'] ?? null;
            $total += is_numeric($amount) ? (int) $amount : 0;
        }

        return new self($metered ? $total : null);
    }
}
