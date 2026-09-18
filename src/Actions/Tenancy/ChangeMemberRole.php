<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Actions\Tenancy;

use Lorisleiva\Actions\Concerns\AsAction;
use Nvade\Numerosis\Enums\Tenancy\MembershipRole;
use Nvade\Numerosis\Exceptions\Tenancy\LastAdminRequired;
use Nvade\Numerosis\Exceptions\Tenancy\OwnerMembershipImmutable;
use Nvade\Numerosis\Models\Central\Membership;

/**
 * `MemberRoleChanged` comes from `MembershipObserver::updated()` instead of
 * here. Ownership moves through tenant-ownership transfer alone, so `Owner`
 * is neither a source nor a destination role.
 *
 * @method static void run(Membership $membership, MembershipRole $role)
 */
class ChangeMemberRole
{
    use AsAction;

    public function handle(Membership $membership, MembershipRole $role): void
    {
        throw_if(
            $membership->isOwner() || $role === MembershipRole::Owner,
            new OwnerMembershipImmutable(__('Ownership is transferred separately, not through a role change.')),
        );

        if ($membership->role === $role) {
            return;
        }

        throw_if($membership->isLastAdmin(), new LastAdminRequired(__('This tenant would be left without an admin.')));

        $membership->update(['role' => $role]);
    }
}
