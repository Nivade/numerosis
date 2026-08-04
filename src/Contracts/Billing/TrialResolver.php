<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Contracts\Billing;

use Nvade\Numerosis\Contracts\Subscribable;

interface TrialResolver
{
    public function daysFor(Plan $plan, ?Subscribable $for): ?int;
}
