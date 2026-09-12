<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Events\Billing;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Nvade\Numerosis\Models\Central\Tenant;

class TenantSuspended
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly Tenant $tenant,
        public readonly string $tenantId,
    ) {}
}
