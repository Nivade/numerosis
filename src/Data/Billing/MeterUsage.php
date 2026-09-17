<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Data\Billing;

use Spatie\LaravelData\Data;

/**
 * One meter's current-period usage as the customer sees it. Read from the local
 * counter, never from Stripe: Stripe's aggregation lags, and two numbers that
 * disagree on the same screen is a support ticket.
 */
class MeterUsage extends Data
{
    public function __construct(
        public string $key,
        public string $event_name,
        public int $used,
        public ?int $included,
        public BillingPeriod $period,
    ) {}

    /** Null when the plan includes no allowance, which is uncapped rather than zero. */
    public function remaining(): ?int
    {
        return $this->included === null ? null : max(0, $this->included - $this->used);
    }

    public function overage(): int
    {
        return $this->included === null ? 0 : max(0, $this->used - $this->included);
    }
}
