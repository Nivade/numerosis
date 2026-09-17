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
        // A closed workspace is already unreachable with its purge date set,
        // so it blocks nothing here. The row stays ownerless until then and
        // nobody can reopen it.
        $isOwner = $user->tenants()
            ->wherePivot('role', MembershipRole::Owner->value)
            ->whereNull('closed_at')
            ->exists();

        if ($isOwner) {
            return false;
        }

        $globalId = $user->global_id;
        $email = $user->email;

        event(new UserAccountDeleting($user, $globalId));

        // Anonymizes instead of deleting. A soft delete would leave the name
        // and address in the row, and a hard delete would drag away the
        // workspace content attached to the tenant-side twin.
        $deleted = AnonymizeUser::run($user);

        if ($deleted) {
            event(new UserAccountDeleted($globalId, $email));
        }

        return $deleted;
    }
}
