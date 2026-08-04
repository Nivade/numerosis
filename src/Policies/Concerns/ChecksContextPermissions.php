<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Policies\Concerns;

use Nvade\Numerosis\Models\User;

/**
 * The standard CRUD policy over Spatie permissions named `"<action> <context>"`.
 *
 * `RolePolicy` and `PermissionPolicy` were byte-identical bar the noun, and
 * `UserPolicy`/`InvitationPolicy` were the same seven methods with one or two
 * genuine deviations each. The permission names come from the same vocabulary
 * {@see \Nvade\Numerosis\Models\Permission::actionsFor()} seeds, so writing them out per
 * policy was three copies of one list that had to agree by hand.
 *
 * Two behaviours are deliberately baked in rather than left to each policy:
 *
 * - **`updateAny`/`deleteAny` short-circuit before the per-record check.** That
 *   is the pattern every existing policy already implemented; a policy that
 *   forgets it silently withholds a permission an admin was granted.
 * - **`hasPermissionTo()` is called with no guard argument**, so Spatie resolves
 *   the guard from the *model* (`Guard::getNames()`) rather than the ambient
 *   default. Passing the request's guard agrees with the model on every normal
 *   path and diverges exactly where it matters — a `CentralUser` evaluated
 *   inside tenant context, as `Authenticate::authenticate()` does, checked
 *   against tenant roles it can never hold. See .claude/rules/auth-guards.md.
 *   Keeping the call in one place is the point: it cannot drift back in seven
 *   files at once.
 *
 * Policies with real domain logic (`ChannelPolicy`, `MessagePolicy`,
 * `ModulePolicy`) are not built on this and should not be.
 */
trait ChecksContextPermissions
{
    /**
     * The permission-name suffix for this policy's subject, e.g. `'roles'`.
     */
    abstract protected function permissionContext(): string;

    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo($this->permission('viewAny'));
    }

    public function view(User $user): bool
    {
        return $user->hasPermissionTo($this->permission('view'));
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo($this->permission('create'));
    }

    public function update(User $user): bool
    {
        return $this->allowsAnyOr($user, 'updateAny', 'update');
    }

    public function delete(User $user): bool
    {
        return $this->allowsAnyOr($user, 'deleteAny', 'delete');
    }

    public function restore(User $user): bool
    {
        return $user->hasPermissionTo($this->permission('restore'));
    }

    public function forceDelete(User $user): bool
    {
        return $user->hasPermissionTo($this->permission('forceDelete'));
    }

    /**
     * The blanket `*Any` permission, falling back to the per-record one.
     */
    protected function allowsAnyOr(User $user, string $anyAction, string $action): bool
    {
        if ($user->hasPermissionTo($this->permission($anyAction))) {
            return true;
        }

        return $user->hasPermissionTo($this->permission($action));
    }

    /**
     * Build a permission name for this policy's context.
     */
    protected function permission(string $action): string
    {
        return $action.' '.$this->permissionContext();
    }
}
