<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Events\Invitations;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Nvade\Numerosis\Models\Central\Invitation;

/**
 * `SerializesModels` is what gives `SendInvitationNotification`'s
 * `#[DeleteWhenMissingModels]` something to act on. Without it the model is
 * serialized whole into the queue payload, never restored by identifier, and
 * the attribute is inert.
 */
class InvitationCreated implements ShouldDispatchAfterCommit
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly Invitation $invitation,
        public readonly int $invitationId,
    ) {}
}
