<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Policies\Auth;

use Illuminate\Auth\Access\HandlesAuthorization;
use Nvade\Numerosis\Enums\Auth\PermissionContext;
use Nvade\Numerosis\Policies\Concerns\ChecksContextPermissions;

class PermissionPolicy
{
    use ChecksContextPermissions;
    use HandlesAuthorization;

    protected function permissionContext(): string
    {
        return PermissionContext::Permissions->value;
    }
}
