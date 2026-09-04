<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Actions\Tenancy;

use Lorisleiva\Actions\Concerns\AsAction;
use Nvade\Numerosis\Enums\Tenancy\MembershipRole;
use Nvade\Numerosis\Models\Central\CentralUser;
use Nvade\Numerosis\Models\Central\Tenant;

/**
 * Only attaches. The tenant-side user row and the `MemberJoined` event both
 * come from `MembershipObserver::created()` — attaching through the
 * relation (never `Membership::create()` or a raw insert) is what makes that
 * fire.
 *
 * `$invitedBy` is the inviter's `global_id`: the pivot's `invited_by` column
 * is a foreign key onto `users.global_id`, never the numeric primary key
 * `Invitation::invited_by_user_id` carries.
 *
 * @method static void run(Tenant $tenant, CentralUser $user, MembershipRole $role, ?string $invitedBy)
 */
class AddTenantMember
{
    use AsAction;

    public function handle(Tenant $tenant, CentralUser $user, MembershipRole $role, ?string $invitedBy): void
    {
        if ($user->tenants()->where('tenants.id', $tenant->getKey())->exists()) {
            return;
        }

        $user->tenants()->attach($tenant, [
            'role' => $role,
            'invited_by' => $invitedBy,
            'invited_at' => now(),
            'joined_at' => now(),
        ]);
    }
}
