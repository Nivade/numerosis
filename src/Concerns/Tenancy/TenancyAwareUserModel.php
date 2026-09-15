<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Concerns\Tenancy;

use Nvade\Numerosis\Enums\Tenancy\Context;

trait TenancyAwareUserModel
{
    public function userModel(): string
    {
        return Context::current()->userModel();
    }
}
