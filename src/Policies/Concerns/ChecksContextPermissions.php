<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Policies\Concerns;

use Nvade\Numerosis\Enums\Auth\PermissionAction;
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
        return $user->hasPermissionTo($this->permission(PermissionAction::ViewAny));
    }

    public function view(User $user): bool
    {
        return $user->hasPermissionTo($this->permission(PermissionAction::View));
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo($this->permission(PermissionAction::Create));
    }

    public function update(User $user): bool
    {
        return $this->allowsAnyOr($user, PermissionAction::UpdateAny, PermissionAction::Update);
    }

    public function delete(User $user): bool
    {
        return $this->allowsAnyOr($user, PermissionAction::DeleteAny, PermissionAction::Delete);
    }

    public function restore(User $user): bool
    {
        return $user->hasPermissionTo($this->permission(PermissionAction::Restore));
    }

    public function forceDelete(User $user): bool
    {
        return $user->hasPermissionTo($this->permission(PermissionAction::ForceDelete));
    }

    /**
     * The blanket `*Any` permission, falling back to the per-record one.
     */
    protected function allowsAnyOr(User $user, PermissionAction $anyAction, PermissionAction $action): bool
    {
        if ($user->hasPermissionTo($this->permission($anyAction))) {
            return true;
        }

        return $user->hasPermissionTo($this->permission($action));
    }

    /**
     * Build a permission name for this policy's context.
     */
    protected function permission(PermissionAction $action): string
    {
        return $action->value.' '.$this->permissionContext();
    }
}
