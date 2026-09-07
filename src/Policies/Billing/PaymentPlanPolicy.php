<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Policies\Billing;

use Illuminate\Auth\Access\HandlesAuthorization;
use Nvade\Numerosis\Policies\Concerns\ChecksContextPermissions;

class PaymentPlanPolicy
{
    use ChecksContextPermissions;
    use HandlesAuthorization;

    protected function permissionContext(): string
    {
        return 'payment_plans';
    }
}
