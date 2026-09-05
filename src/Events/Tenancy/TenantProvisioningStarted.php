<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Events\Tenancy;

use Illuminate\Foundation\Events\Dispatchable;

class TenantProvisioningStarted
{
    use Dispatchable;

    public function __construct(
        public readonly string $domain,
        public readonly ?string $globalId,
    ) {}
}
