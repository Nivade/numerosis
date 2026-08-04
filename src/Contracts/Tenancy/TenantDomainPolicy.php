<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Contracts\Tenancy;

use Illuminate\Validation\ValidationException;

interface TenantDomainPolicy
{
    /**
     * @throws ValidationException if the domain cannot be claimed
     */
    public function assertAvailable(string $domain): void;
}
