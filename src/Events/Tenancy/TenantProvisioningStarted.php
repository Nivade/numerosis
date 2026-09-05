<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Events\Tenancy;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Not broadcast, unlike `TenantProvisioningFailed`/`TenantProvisioningCancelled`:
 * nothing in `resources/views` listens for it client-side.
 */
class TenantProvisioningStarted
{
    use Dispatchable;

    public function __construct(
        public readonly string $domain,
        public readonly ?string $globalId,
    ) {}
}
