<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Policies;

use Illuminate\Auth\Access\HandlesAuthorization;
use Nvade\Numerosis\Policies\Concerns\ChecksContextPermissions;

/**
 * Central catalog management (which modules exist to sell, at what price) —
 * not to be confused with {@see ModulePolicy}, which gates a tenant buying
 * one. Same 'modules' permission context as the `modules` table itself, but
 * guard-scoped apart from ModulePolicy's tenant-guard permissions of the same
 * name (Spatie permissions are guard-scoped, so 'viewAny modules' for guard
 * 'web' and guard 'tenant' are distinct rows).
 */
class ModuleOfferingPolicy
{
    use ChecksContextPermissions;
    use HandlesAuthorization;

    protected function permissionContext(): string
    {
        return 'modules';
    }
}
