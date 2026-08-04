<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Services\Billing\Resolvers;

use Nvade\Numerosis\Actions\Queries\GetAuthenticatedUser;
use Nvade\Numerosis\Contracts\Billing\BillableResolver;
use Illuminate\Database\Eloquent\Model;

class TenantOrUserBillableResolver implements BillableResolver
{
    public function resolve(): ?Model
    {
        if (tenancy()->initialized) {
            $currentTenant = tenant();

            return $currentTenant instanceof Model ? $currentTenant : null;
        }

        return GetAuthenticatedUser::run();
    }
}
