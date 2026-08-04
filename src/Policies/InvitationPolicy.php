<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Policies;

use Nvade\Numerosis\Models\Tenant\Invitation;
use Nvade\Numerosis\Models\User;
use Nvade\Numerosis\Policies\Concerns\ChecksContextPermissions;
use Illuminate\Auth\Access\HandlesAuthorization;

class InvitationPolicy
{
    use ChecksContextPermissions;
    use HandlesAuthorization;

    protected function permissionContext(): string
    {
        return 'invitations';
    }

    /**
     * Any member of the tenant may see who has been invited.
     */
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Invitation $invitation): bool
    {
        return true;
    }

    /**
     * Without `deleteAny invitations`, `delete invitations` only revokes an
     * invitation the user sent themselves.
     */
    public function delete(User $user, Invitation $invitation): bool
    {
        if ($user->hasPermissionTo('deleteAny invitations')) {
            return true;
        }

        if ($invitation->invited_by === $user->id) {
            return $user->hasPermissionTo('delete invitations');
        }

        return false;
    }
}
