<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Policies\Tenancy;

use Illuminate\Auth\Access\HandlesAuthorization;
use Nvade\Numerosis\Enums\Auth\PermissionAction;
use Nvade\Numerosis\Enums\Auth\PermissionContext;
use Nvade\Numerosis\Enums\Tenancy\MembershipRole;
use Nvade\Numerosis\Models\Central\Membership;
use Nvade\Numerosis\Models\User;
use Stancl\Tenancy\Contracts\Tenant as TenancyTenant;

/**
 * Typed against the tenant `User`, like {@see \Nvade\Numerosis\Policies\Invitations\InvitationPolicy}:
 * the team screen lives on the tenant domain, and the acting user's twin
 * there is what `hasPermissionTo()` resolves against.
 */
class MembershipPolicy
{
    use HandlesAuthorization;

    /** Seeing who is in the team is open to every member of it. */
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function update(User $user, Membership $membership): bool
    {
        if (! $this->belongsToCurrentTenant($membership) || $membership->isOwner()) {
            return false;
        }

        return $this->manages($user, $membership->tenant_id);
    }

    public function delete(User $user, Membership $membership): bool
    {
        if (! $this->belongsToCurrentTenant($membership) || $membership->isOwner()) {
            return false;
        }

        // Leaving is always available to a non-owner, including the last
        // admin: the owner can promote a replacement, and a member with no
        // way out of a team is the worse failure.
        if ($membership->global_user_id === $user->global_id) {
            return true;
        }

        if (! $this->manages($user, $membership->tenant_id)) {
            return false;
        }

        return ! $membership->isLastAdmin();
    }

    /** Only the sitting owner nominates, and only an accepted non-owner can be nominated. */
    public function transferOwnership(User $user, Membership $membership): bool
    {
        if (! $this->belongsToCurrentTenant($membership) || $membership->isOwner() || $membership->joined_at === null) {
            return false;
        }

        $role = Membership::query()
            ->where('tenant_id', $membership->tenant_id)
            ->where('global_user_id', $user->global_id)
            ->value('role');

        return $role === MembershipRole::Owner;
    }

    /**
     * `memberships` is a central table, so binding `{membership}` on a tenant
     * route resolves any id regardless of owner, and every tenant's `admin`
     * holds `deleteAny invitations`.
     */
    private function belongsToCurrentTenant(Membership $membership): bool
    {
        $tenant = tenant();

        return $tenant instanceof TenancyTenant
            && $membership->tenant_id === (string) $tenant->getTenantKey();
    }

    private function manages(User $user, string $tenantId): bool
    {
        if ($user->hasPermissionTo(PermissionAction::DeleteAny->value.' '.PermissionContext::Invitations->value)) {
            return true;
        }

        $role = Membership::query()
            ->where('tenant_id', $tenantId)
            ->where('global_user_id', $user->global_id)
            ->value('role');

        return in_array($role, [MembershipRole::Owner, MembershipRole::Admin], true);
    }
}
