<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Contracts\Billing;

use Nvade\Numerosis\Contracts\Subscribable;

/**
 * Both asked on the membership path, where no plan is being chosen. An
 * invitation is judged against the seats already spoken for, a join
 * against the members actually present.
 */
interface SeatPolicy
{
    public function hasSeatForNewInvitation(Subscribable $for): bool;

    public function hasSeatForNewMember(Subscribable $for): bool;
}
