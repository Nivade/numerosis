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

    /** @var array<string, MembershipRole|null> */
    private array $roles = [];

    /** Seeing who is in the team is open to every member of it. */
    public function viewAny(User $user): bool
    {
        return true;
    }

    /**
     * The audit trail names who removed whom and when a role changed. That
     * is management information, kept out of team-facing views.
     */
    public function viewActivity(User $user): bool
    {
        $tenant = tenant();

        return $tenant instanceof TenancyTenant
            && $this->manages($user, (string) $tenant->getTenantKey());
    }

    /**
     * What the workspace pays and what it has consumed. No tenant screen shows
     * this, so the API is the only reader and the gate has to be stated here.
     */
    public function viewBilling(User $user): bool
    {
        $tenant = tenant();

        return $tenant instanceof TenancyTenant
            && $this->manages($user, (string) $tenant->getTenantKey());
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

        return $this->roleFor($membership->tenant_id, $user->global_id) === MembershipRole::Owner;
    }

    /** Closing and reopening a workspace is the owner's own decision, on their own membership row. */
    public function manageClosure(User $user, Membership $membership): bool
    {
        return $this->belongsToCurrentTenant($membership)
            && $membership->isOwner()
            && $membership->global_user_id === $user->global_id;
    }

    /** Requiring a second factor of everyone is the owner's decision too. */
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

        return in_array(
            $this->roleFor($tenantId, $user->global_id),
            [MembershipRole::Owner, MembershipRole::Admin],
            true,
        );
    }

    /**
     * Memoized: the team screen asks `update` and `delete` of every row, and
     * the acting user's role is the same answer each time.
     */
    private function roleFor(string $tenantId, ?string $globalUserId): ?MembershipRole
    {
        $key = $tenantId.'|'.($globalUserId ?? '');

        return $this->roles[$key] ??= Membership::roleFor($tenantId, $globalUserId);
    }
}
