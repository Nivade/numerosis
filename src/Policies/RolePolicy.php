<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Policies;

use Illuminate\Auth\Access\HandlesAuthorization;
use Nvade\Numerosis\Policies\Concerns\ChecksContextPermissions;

class RolePolicy
{
    use ChecksContextPermissions;
    use HandlesAuthorization;

    protected function permissionContext(): string
    {
        return 'roles';
    }
}
