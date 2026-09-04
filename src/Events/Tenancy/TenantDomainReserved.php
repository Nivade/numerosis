<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Events\Tenancy;

use Illuminate\Foundation\Events\Dispatchable;
use Nvade\Numerosis\Enums\Tenancy\IdentificationMode;

/**
 * A real `domains` row now exists for this tenant. The seam for DNS
 * automation and certificate issuance under `IdentificationMode::CustomDomain`.
 */
class TenantDomainReserved
{
    use Dispatchable;

    public function __construct(
        public readonly string $tenantId,
        public readonly string $domain,
        public readonly IdentificationMode $mode,
    ) {}
}
