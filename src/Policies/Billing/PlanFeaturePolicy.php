<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Policies\Billing;

use Illuminate\Auth\Access\HandlesAuthorization;
use Nvade\Numerosis\Enums\Auth\PermissionContext;
use Nvade\Numerosis\Policies\Concerns\ChecksContextPermissions;

class PlanFeaturePolicy
{
    use ChecksContextPermissions;
    use HandlesAuthorization;

    protected function permissionContext(): string
    {
        return PermissionContext::Features->value;
    }
}
