<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Policies\Tenancy;

use Illuminate\Auth\Access\HandlesAuthorization;
use Nvade\Numerosis\Enums\Auth\PermissionContext;
use Nvade\Numerosis\Models\User;
use Nvade\Numerosis\Policies\Concerns\ChecksContextPermissions;

class TenantPolicy
{
    use ChecksContextPermissions;
    use HandlesAuthorization;

    /**
     * Seeded on the central guard only, by
     * {@see \Nvade\Numerosis\Database\Seeders\RoleAndPermissionSeeder}, and
     * withheld separately from the CRUD actions so a staff user may
     * administer tenants without being able to sign in as their members.
     */
    public const string IMPERSONATE = 'impersonate';

    /**
     * Separate from `restore`, which undoes a suspension: closure is the
     * owner's own decision and reversing it is a different call.
     */
    public const string REOPEN = 'reopen';

    public function impersonate(User $user): bool
    {
        return $user->hasPermissionTo(self::IMPERSONATE.' '.$this->permissionContext());
    }

    public function reopen(User $user): bool
    {
        return $user->hasPermissionTo(self::REOPEN.' '.$this->permissionContext());
    }

    protected function permissionContext(): string
    {
        return PermissionContext::Tenants->value;
    }
}
