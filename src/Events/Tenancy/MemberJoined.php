<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Events\Tenancy;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Nvade\Numerosis\Enums\Tenancy\MembershipRole;

/**
 * `$role` is the `memberships.role` enum and never a bare string, so a
 * listener doing seat-based billing matches on `MembershipRole::Owner` without
 * repeating the literal. Backed enums round-trip through a queued payload
 * without the re-query that makes a model unsafe here.
 *
 * Dispatched from `MembershipObserver::created()`, which `AcceptInvitation`
 * reaches from inside a transaction, hence `ShouldDispatchAfterCommit`.
 */
class MemberJoined implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public readonly string $tenantId,
        public readonly string $globalUserId,
        public readonly MembershipRole $role,
        public readonly ?string $invitedBy,
    ) {}
}
