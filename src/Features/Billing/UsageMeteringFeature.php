<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Features\Billing;

use Nvade\Numerosis\Contracts\NamedFeature;
use Nvade\Numerosis\Features\Concerns\IsNamedFeature;

/**
 * The tenant-facing usage screen. Reporting counters to Stripe is
 * `numerosis.schedule.report_usage`, not this: a host may bill usage without
 * showing the breakdown, and hiding the screen must never stop the meter.
 */
class UsageMeteringFeature implements NamedFeature
{
    use IsNamedFeature;

    public const NAME = 'usage_metering';
}
