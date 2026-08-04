<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Concerns;

use Nvade\Numerosis\Services\Tenancy\UserModelResolver;

trait TenancyAwareUserModel
{
    public function userModel(): string
    {
        return tenancy()->initialized
            ? UserModelResolver::tenantUserModel()
            : UserModelResolver::centralUserModel();
    }
}
