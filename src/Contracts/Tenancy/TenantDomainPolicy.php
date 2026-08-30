<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Contracts\Tenancy;

use Illuminate\Validation\ValidationException;

interface TenantDomainPolicy
{
    /**
     * $domain is always the tenant's safe id/slug — the value the wizard's
     * "domain" field holds in every identification mode, format-checked the
     * same way regardless (see Nvade\Numerosis\Enums\Tenancy\IdentificationMode).
     *
     * @throws ValidationException if the domain cannot be claimed
     */
    public function assertAvailable(string $domain): void;

    /**
     * Only relevant under IdentificationMode::CustomDomain — $domain here is
     * the tenant's own fully-qualified domain (e.g. "app.acme.com"), checked
     * against a different format and a different uniqueness scope than
     * assertAvailable() above.
     *
     * @throws ValidationException if the domain cannot be claimed
     */
    public function assertCustomDomainAvailable(string $domain): void;
}
