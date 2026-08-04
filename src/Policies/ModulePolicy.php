<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Policies;

use Nvade\Numerosis\Models\Central\Tenant;
use Nvade\Numerosis\Models\Tenant\Module;
use Nvade\Numerosis\Models\Tenant\User as TenantUser;
use Nvade\Numerosis\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;
use Spatie\Permission\Exceptions\PermissionDoesNotExist;

class ModulePolicy
{
    use HandlesAuthorization;

    /**
     * Determine whether the user hasPermissionTo view any models.
     */
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('viewAny modules');
    }

    /**
     * Determine whether the user hasPermissionTo view the model.
     */
    public function view(User $user, Module $module): bool
    {
        return $user->hasPermissionTo('view modules');
    }

    /**
     * Determine whether the user hasPermissionTo create models.
     */
    public function create(User $user): bool
    {
        return $user->hasPermissionTo('create modules');
    }

    /**
     * Determine whether the user hasPermissionTo update the model.
     */
    public function update(User $user, Module $module): bool
    {
        if ($user->hasPermissionTo('updateAny modules')) {
            return true;
        }

        return $user->hasPermissionTo('update modules');
    }

    /**
     * Determine whether the user hasPermissionTo delete the model.
     */
    public function delete(User $user, Module $module): bool
    {
        if ($user->hasPermissionTo('deleteAny modules')) {
            return true;
        }

        return $user->hasPermissionTo('delete modules');
    }

    /**
     * Determine whether the user hasPermissionTo restore the model.
     */
    public function restore(User $user, Module $module): bool
    {
        return $user->hasPermissionTo('restore modules');
    }

    /**
     * Determine whether the user hasPermissionTo permanently delete the model.
     */
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
     * Held to the same rule as purchase(): before this existed, any user with
     * `viewAny modules` could cancel a module nobody but the owner could buy.
     */
    public function cancel(User $user, Module $module): bool
    {
        return $this->hasModuleBillingPermission($user, 'cancel modules');
    }

    /**
     * The tenant owner is always allowed, with or without a permission row —
     * they are whoever Stripe invoices, and they must not be able to lock
     * themselves out of their own billing by editing roles. Everyone else
     * needs the named permission, which only exists on the tenant guard, so a
     * CentralUser evaluated inside tenant context is refused rather than
     * checked against roles it can never hold (see
     * .claude/rules/auth-guards.md).
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
            // Spatie throws rather than returning false when no permission of
            // that name exists for the guard — normally a signal worth letting
            // through as a 500 (.claude/rules/auth-guards.md). Not here: these
            // two permissions arrive by tenant migration, so between deploying
            // this code and `tenants:migrate` reaching a given tenant, the row
            // legitimately does not exist yet. Denying (and reporting) keeps
            // the panel usable in that window, and costs nothing — the owner
            // never reaches this line.
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
