<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Services\Billing;

use Illuminate\Database\Eloquent\Model;
use Nvade\Numerosis\Actions\Queries\GetAuthenticatedUser;
use Nvade\Numerosis\Contracts\Billing\BillableResolver;
use Nvade\Numerosis\Contracts\Billing\BillableUser;
use Nvade\Numerosis\Contracts\Subscribable;

class TenantOrUserBillableResolver implements BillableResolver
{
    public function resolve(): null|(Model&BillableUser)|(Model&Subscribable)
    {
        if (tenancy()->initialized) {
            $currentTenant = tenant();

            return $currentTenant instanceof Subscribable && $currentTenant instanceof Model ? $currentTenant : null;
        }

        $user = GetAuthenticatedUser::run();

        // Subscribable alone is enough: PlanPolicy takes it, and the Cashier
        // paths that need more narrow on BillableUser themselves and throw.
        return $user instanceof BillableUser || $user instanceof Subscribable ? $user : null;
    }
}
