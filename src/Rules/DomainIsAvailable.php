<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Rules;

use Nvade\Numerosis\Contracts\Tenancy\TenantDomainPolicy;

/**
 * Format, reserved words, and whether a live tenant already holds the
 * identifier. Deliberately does not cover tenant_provisions.
 *
 * @see \Nvade\Numerosis\Services\Tenancy\DefaultTenantDomainPolicy
 */
class DomainIsAvailable extends TenantDomainRule
{
    protected function assertAvailable(TenantDomainPolicy $policy, string $domain): void
    {
        $policy->assertAvailable($domain);
    }
}
