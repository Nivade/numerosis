<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Events\Tenancy;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class TenantProvisioningFailed
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly string $domain,
        public readonly ?string $globalId,
    ) {}
}
