<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Policies\Auth;

use Illuminate\Auth\Access\HandlesAuthorization;
use Nvade\Numerosis\Models\User;
use Nvade\Numerosis\Policies\Concerns\ChecksContextPermissions;

class UserPolicy
{
    use ChecksContextPermissions;
    use HandlesAuthorization;

    protected function permissionContext(): string
    {
        return 'users';
    }

    /**
     * A user may always read their own record; otherwise `viewAny users`
     * applies, since being allowed to list users implies being allowed to
     * read any row in that list.
     *
     * Identity is compared with `is()`, never by id. Ids are per-database
     * integers, so an unrelated central and tenant user can share one.
     */
    public function view(User $user, User $model): bool
    {
        return $user->is($model) || $this->allowsAnyOr($user, 'viewAny', 'view');
    }

    /**
     * A user may always edit their own record.
     */
    public function update(User $user, User $model): bool
    {
        return $user->is($model) || $this->allowsAnyOr($user, 'updateAny', 'update');
    }
}
