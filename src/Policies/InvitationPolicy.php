<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Policies;

use Illuminate\Auth\Access\HandlesAuthorization;
use Nvade\Numerosis\Models\Central\Invitation;
use Nvade\Numerosis\Models\User;
use Nvade\Numerosis\Policies\Concerns\ChecksContextPermissions;
use Stancl\Tenancy\Contracts\Tenant as TenancyTenant;

/**
 * Typed against the tenant `User`, because issuing happens in tenant context
 * where `hasPermissionTo()` resolves. Acceptance is not a policy check.
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
     * tenant domain resolves any ULID regardless of which tenant owns it, and
     * every tenant's `admin` holds `deleteAny invitations`. The tenant check
     * below is what stops one tenant's admin revoking another's invitation.
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

        // Through the relation, not a per-row exists(): the listing eager
        // loads `invitedBy`, so an N-row list costs one query rather than N.
        $inviterGlobalId = $invitation->invitedBy?->global_id;

        return $inviterGlobalId !== null && $inviterGlobalId === $user->global_id;
    }
}
