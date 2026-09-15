<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Contracts\Tenancy;

use Nvade\Numerosis\Contracts\Auth\CentralUserModel;

interface HasTenantOwner
{
    /**
     * Core's `Tenant` answers this from a cached `global_id` through
     * `FindUserByGlobalId`, so the returned model carries no `Membership`
     * pivot. Read the pivot off `users()` when you need it.
     */
    public function owner(): ?CentralUserModel;
}
