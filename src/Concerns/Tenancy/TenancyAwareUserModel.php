<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Concerns\Tenancy;

use Nvade\Numerosis\Boot\UserModels;

trait TenancyAwareUserModel
{
    public function userModel(): string
    {
        return UserModels::current();
    }
}
