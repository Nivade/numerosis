<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Actions\Auth;

use Lorisleiva\Actions\Concerns\AsAction;
use Nvade\Numerosis\Enums\Tenancy\MembershipRole;
use Nvade\Numerosis\Events\Auth\UserAccountDeleted;
use Nvade\Numerosis\Events\Auth\UserAccountDeleting;
use Nvade\Numerosis\Models\Central\CentralUser;

class DeleteUserAccount
{
    use AsAction;

    public function handle(CentralUser $user): bool
    {
        $isOwner = $user->tenants()
            ->wherePivot('role', MembershipRole::Owner->value)
            ->exists();

        if ($isOwner) {
            return false;
        }

        $globalId = $user->global_id;
        $email = $user->email;

        event(new UserAccountDeleting($user, $globalId));

        $deleted = (bool) $user->delete();

        if ($deleted) {
            event(new UserAccountDeleted($globalId, $email));
        }

        return $deleted;
    }
}
