<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Policies;

use Nvade\Numerosis\Policies\Concerns\ChecksContextPermissions;
use Illuminate\Auth\Access\HandlesAuthorization;

class PermissionPolicy
{
    use ChecksContextPermissions;
    use HandlesAuthorization;

    protected function permissionContext(): string
    {
        return 'permissions';
    }
}
