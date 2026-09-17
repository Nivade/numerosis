<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Data\Billing;

use Illuminate\Support\Carbon;
use Spatie\LaravelData\Data;

/**
 * The window a meter is reported for. `anchored` is false when Stripe's own
 * period was unavailable and the window was derived from the subscription's
 * anniversary instead.
 */
class BillingPeriod extends Data
{
    public function __construct(
        public Carbon $start,
        public Carbon $end,
        public bool $anchored = true,
    ) {}

    /** The counter bucket this period writes to. */
    public function bucket(): Carbon
    {
        return $this->start->copy()->startOfDay();
    }

    public function contains(Carbon $moment): bool
    {
        return $moment->betweenIncluded($this->start, $this->end);
    }
}
