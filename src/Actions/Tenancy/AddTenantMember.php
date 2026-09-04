<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Actions\Tenancy;

use Lorisleiva\Actions\Concerns\AsAction;
use Nvade\Numerosis\Enums\Tenancy\MembershipRole;
use Nvade\Numerosis\Models\Central\CentralUser;
use Nvade\Numerosis\Models\Central\Tenant;

/**
 * Only attaches, and only through the relation: `Membership::create()` or a
 * raw insert skips `MembershipObserver::created()`, which is what produces the
 * tenant-side user row and the `MemberJoined` event. `$invitedBy` is the
 * inviter's `global_id`, since the pivot's `invited_by` is a foreign key onto
 * `users.global_id` and never `Invitation::invited_by_user_id`.
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
