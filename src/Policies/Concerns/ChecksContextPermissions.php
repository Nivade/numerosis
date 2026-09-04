<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Policies\Concerns;

use Nvade\Numerosis\Models\User;

/**
 * Standard CRUD policy over permissions named `"<action> <context>"`, drawn
 * from the vocabulary {@see \Nvade\Numerosis\Models\Permission::actionsFor()}
 * seeds. Compose it and declare {@see self::permissionContext()}. Each check
 * resolves against the model's own guard, and `updateAny`/`deleteAny`
 * short-circuit the per-record check.
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
