<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Actions\Tenancy;

use Lorisleiva\Actions\Concerns\AsAction;
use Nvade\Numerosis\Exceptions\Tenancy\OwnerMembershipImmutable;
use Nvade\Numerosis\Models\Central\Membership;

/**
 * Deletes through the model, never a pivot detach or a raw delete, so
 * `MembershipObserver::deleted()` fires `MemberRemoved`. The tenant-side
 * `User` row is left in place: removal revokes access, it does not erase
 * the person's records.
 *
 * @method static void run(Membership $membership)
 */
class RemoveMember
{
    use AsAction;

    public function handle(Membership $membership): void
    {
        throw_if($membership->isOwner(), new OwnerMembershipImmutable(__('The owner cannot be removed from the team.')));

        $membership->delete();
    }
}
