<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Policies;

use Illuminate\Auth\Access\HandlesAuthorization;
use Nvade\Numerosis\Models\Central\Tenant;
use Nvade\Numerosis\Models\Tenant\Module;
use Nvade\Numerosis\Models\Tenant\User as TenantUser;
use Nvade\Numerosis\Models\User;
use Spatie\Permission\Exceptions\PermissionDoesNotExist;

class ModulePolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('viewAny modules');
    }

    public function view(User $user, Module $module): bool
    {
        return $user->hasPermissionTo('view modules');
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo('create modules');
    }

    public function update(User $user, Module $module): bool
    {
        if ($user->hasPermissionTo('updateAny modules')) {
            return true;
        }

        return $user->hasPermissionTo('update modules');
    }

    public function delete(User $user, Module $module): bool
    {
        if ($user->hasPermissionTo('deleteAny modules')) {
            return true;
        }

        return $user->hasPermissionTo('delete modules');
    }

    public function restore(User $user, Module $module): bool
    {
        return $user->hasPermissionTo('restore modules');
    }

    public function forceDelete(User $user, Module $module): bool
    {
        return $user->hasPermissionTo('forceDelete modules');
    }

    /**
     * Determine whether the user may buy a module for the current tenant.
     *
     * Separate from `create modules` on purpose: that permission is about
     * managing the tenant's module rows, this one spends the tenant's money.
     */
    public function purchase(User $user): bool
    {
        return $this->hasModuleBillingPermission($user, 'purchase modules');
    }

    /**
     * Determine whether the user may stop billing for a purchased module.
     *
     * Held to the same rule as purchasing one: seeing a module is not
     * grounds for cancelling it.
     */
    public function cancel(User $user, Module $module): bool
    {
        return $this->hasModuleBillingPermission($user, 'cancel modules');
    }

    /**
     * The tenant owner is always allowed, with or without a permission row:
     * they are whoever Stripe invoices, and must not be able to lock
     * themselves out of their own billing by editing roles.
     *
     * Everyone else needs the named permission, which exists only on the
     * tenant guard — so a central user is refused rather than checked
     * against roles they could never hold.
     */
    private function hasModuleBillingPermission(User $user, string $permission): bool
    {
        if ($this->isTenantOwner($user)) {
            return true;
        }

        if (! $user instanceof TenantUser) {
            return false;
        }

        try {
            return $user->hasPermissionTo($permission);
        } catch (PermissionDoesNotExist $e) {
            // These two permissions arrive by tenant migration, so between
            // deploying and migrating a given tenant the row legitimately
            // does not exist yet. Deny rather than 500 during that window;
            // the owner is already allowed above and never reaches here.
            report($e);

            return false;
        }
    }

    private function isTenantOwner(User $user): bool
    {
        $tenant = tenant();

        if (! $tenant instanceof Tenant) {
            return false;
        }

        $ownerGlobalId = $tenant->owner()?->global_id;

        return $ownerGlobalId !== null && $ownerGlobalId === $user->global_id;
    }
}
