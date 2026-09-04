<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Policies;

use Illuminate\Auth\Access\HandlesAuthorization;
use Nvade\Numerosis\Models\Central\CentralUser;
use Nvade\Numerosis\Models\Central\Invitation;
use Nvade\Numerosis\Models\User;
use Nvade\Numerosis\Policies\Concerns\ChecksContextPermissions;
use Nvade\Numerosis\Support\Numerosis;
use Stancl\Tenancy\Contracts\Tenant as TenancyTenant;

/**
 * Typed against the tenant `User` — issuing happens in tenant context, where
 * `hasPermissionTo()` resolves. Acceptance is not a policy check: the
 * invitee has no tenant user yet, and identity matching happens inside
 * `AcceptInvitation`.
 */
class InvitationPolicy
{
    use ChecksContextPermissions;
    use HandlesAuthorization;

    protected function permissionContext(): string
    {
        return 'invitations';
    }

    /**
     * `tenant_invitations` is a central table, so route-model binding on the
     * tenant domain resolves any ULID regardless of which tenant owns it. The
     * seeder grants every context's actions to the `admin` role in every
     * tenant, so without this check an admin of one tenant could revoke
     * another tenant's invitation given its ULID, which every invitee already
     * holds from their emailed link.
     *
     * Without `deleteAny invitations`, a user may only revoke an invitation
     * they sent themselves. `invited_by_user_id` is the central user's primary
     * key while the acting `$user` is the tenant twin, so the two are joined
     * through `global_id` and never compared directly.
     */
    public function delete(User $user, Invitation $invitation): bool
    {
        $tenant = tenant();

        if (! $tenant instanceof TenancyTenant || $invitation->tenant_id !== (string) $tenant->getTenantKey()) {
            return false;
        }

        if ($user->hasPermissionTo('deleteAny invitations')) {
            return true;
        }

        if (! $user->hasPermissionTo('delete invitations')) {
            return false;
        }

        $centralUserClass = Numerosis::model(CentralUser::class);

        return $invitation->invited_by_user_id !== null
            && $centralUserClass::where('id', $invitation->invited_by_user_id)
                ->where('global_id', $user->global_id)
                ->exists();
    }
}
