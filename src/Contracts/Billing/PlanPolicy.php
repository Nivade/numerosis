<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Contracts\Billing;

use Nvade\Numerosis\Contracts\Subscribable;

interface PlanPolicy
{
    public function assertEligible(Subscribable $for, Plan $plan): void;

    public function canSwap(Subscribable $for, Plan $from, Plan $to): bool;

    /**
     * Both asked on the membership path, where no plan is being chosen. An
     * invitation is judged against the seats already spoken for, a join
     * against the members actually present.
     */
    public function hasSeatForNewInvitation(Subscribable $for): bool;

    public function hasSeatForNewMember(Subscribable $for): bool;
}
