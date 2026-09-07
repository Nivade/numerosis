<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Events\Tenancy;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Nvade\Numerosis\Enums\Tenancy\MembershipRole;

/**
 * Dispatched from `MembershipObserver::deleted()`, which is exposed to the
 * same transaction shape as {@see MemberJoined}: a detach wrapped by a caller.
 */
class MemberRemoved implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public readonly string $tenantId,
        public readonly string $globalUserId,
        public readonly MembershipRole $role,
    ) {}
}
