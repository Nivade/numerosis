<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Policies;

use Nvade\Numerosis\Models\User;
use Nvade\Numerosis\Policies\Concerns\ChecksContextPermissions;
use Illuminate\Auth\Access\HandlesAuthorization;

class UserPolicy
{
    use ChecksContextPermissions;
    use HandlesAuthorization;

    protected function permissionContext(): string
    {
        return 'users';
    }

    /**
     * A user may always read their own record.
     *
     * `viewAny users` — not `view users` — is the blanket permission here,
     * because being allowed to list users is what implies being allowed to read
     * any row in that list.
     *
     * `is()` rather than an `id` comparison: ids are per-database integers, so
     * `$user->id === $model->id` is also true for a `CentralUser` and an
     * unrelated `Tenant\User` that happen to share a primary key. `is()`
     * compares the model class, table and connection too. Same family of bug as
     * the cross-tenant cache leak in .claude/rules/tenant-caching.md.
     */
    public function view(User $user, User $model): bool
    {
        if ($user->hasPermissionTo('viewAny users')) {
            return true;
        }

        return $user->is($model) || $user->hasPermissionTo('view users');
    }

    /**
     * A user may always edit their own record.
     */
    public function update(User $user, User $model): bool
    {
        if ($user->hasPermissionTo('updateAny users')) {
            return true;
        }

        return $user->is($model) || $user->hasPermissionTo('update users');
    }
}
