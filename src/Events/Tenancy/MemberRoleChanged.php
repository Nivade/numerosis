<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Events\Tenancy;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Nvade\Numerosis\Enums\Tenancy\MembershipRole;

/**
 * Dispatched from `MembershipObserver::updated()` whenever `role` changed, so
 * a role written outside {@see \Nvade\Numerosis\Actions\Tenancy\ChangeMemberRole}
 * carries the same event.
 */
class MemberRoleChanged implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public readonly string $tenantId,
        public readonly string $globalUserId,
        public readonly MembershipRole $from,
        public readonly MembershipRole $to,
    ) {}
}
