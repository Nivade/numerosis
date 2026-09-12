<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Contracts\Tenancy;

use Nvade\Numerosis\Contracts\Auth\CentralUserModel;

interface HasTenantOwner
{
    public function owner(): ?CentralUserModel;
}
