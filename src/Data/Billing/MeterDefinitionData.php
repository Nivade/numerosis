<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Data\Billing;

use Spatie\LaravelData\Data;

/**
 * One metered capability on a plan: the counter key it is recorded under, the
 * Stripe meter it is billed through, and how much of it the plan includes
 * before an overage starts.
 */
class MeterDefinitionData extends Data
{
    public function __construct(
        public string $key,
        public string $event_name,
        public ?string $meter_id = null,
        public ?string $price = null,
        public ?int $included = null,
    ) {}

    /**
     * Malformed entries are dropped by the reader instead of repaired here;
     * `numerosis:install` is what reports them.
     *
     * @param  array<array-key, mixed>  $entry
     */
    public static function tryFrom(array $entry): ?self
    {
        $key = $entry['key'] ?? null;
        $event = $entry['event_name'] ?? null;

        if (! is_string($key) || $key === '' || ! is_string($event) || $event === '') {
            return null;
        }

        $meterId = $entry['meter_id'] ?? null;
        $price = $entry['price'] ?? null;
        $included = $entry['included'] ?? null;

        return new self(
            key: $key,
            event_name: $event,
            meter_id: is_string($meterId) && $meterId !== '' ? $meterId : null,
            price: is_string($price) && $price !== '' ? $price : null,
            included: is_numeric($included) ? (int) $included : null,
        );
    }
}
