<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Contracts\Invitations;

use Nvade\Numerosis\Models\Central\CentralUser;
use Nvade\Numerosis\Models\Tenant\Invitation;

/**
 * Creates the central user for someone accepting an invitation who has no
 * existing account. Bind a replacement in a service provider to change how
 * an invited user is created without forking the invitation-accept
 * component.
 */
interface CreatesInvitedUser
{
    public function create(Invitation $invitation, string $name, string $password): CentralUser;
}
