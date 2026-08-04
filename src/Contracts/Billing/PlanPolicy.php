<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Contracts\Billing;

use Nvade\Numerosis\Contracts\Subscribable;

interface PlanPolicy
{
    public function assertEligible(Subscribable $for, Plan $plan): void;

    public function canSwap(Subscribable $for, Plan $from, Plan $to): bool;
}
