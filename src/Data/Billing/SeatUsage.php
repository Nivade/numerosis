<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Data\Billing;

use Spatie\LaravelData\Data;

/**
 * An invitation holds a seat but is not one yet, which is why the two
 * questions below count differently: issuing an invitation a plan could never
 * honour is refused, while accepting one is judged on members alone.
 */
final class SeatUsage extends Data
{
    public function __construct(
        public int $members,
        public int $pendingInvitations,
        public ?int $limit,
    ) {}

    public function used(): int
    {
        return $this->members + $this->pendingInvitations;
    }

    public function hasRoomForAnotherMember(): bool
    {
        return $this->limit === null || $this->members < $this->limit;
    }
}
