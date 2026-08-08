<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Services\Billing\Resolvers;

use Illuminate\Database\Eloquent\Model;
use Nvade\Numerosis\Actions\Queries\GetAuthenticatedUser;
use Nvade\Numerosis\Contracts\Billing\BillableResolver;

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
