<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Events\Billing;

use Nvade\Numerosis\Models\Central\Tenant;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class PaymentFailed
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly Tenant $tenant,
    ) {}
}
