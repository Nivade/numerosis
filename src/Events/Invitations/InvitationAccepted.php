<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Events\Invitations;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Nvade\Numerosis\Models\Central\Invitation;

/**
 * Carries only what {@see \Nvade\Numerosis\Events\Tenancy\MemberJoined} does
 * not: the invitation, its inviter, and how long it sat unaccepted.
 * `MemberJoined` fires automatically from `MembershipObserver::created()`,
 * because acceptance attaches the membership through the relation rather
 * than dispatching it itself.
 */
class InvitationAccepted implements ShouldDispatchAfterCommit
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly Invitation $invitation,
        public readonly int $invitationId,
        public readonly ?int $invitedByUserId,
        public readonly int $secondsUnaccepted,
    ) {}
}
