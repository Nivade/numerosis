<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Events\Invitations;

use Nvade\Numerosis\Models\Tenant\Invitation;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class InvitationIssued
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly Invitation $invitation,
    ) {}
}
