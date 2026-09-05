<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Rules;

use Nvade\Numerosis\Contracts\Tenancy\TenantDomainPolicy;

/**
 * Only used under IdentificationMode::CustomDomain, on the wizard's separate
 * custom-domain field.
 *
 * @see DomainIsAvailable the id/slug counterpart
 */
class CustomDomainIsAvailable extends TenantDomainRule
{
    protected function assertAvailable(TenantDomainPolicy $policy, string $domain): void
    {
        $policy->assertCustomDomainAvailable($domain);
    }
}
