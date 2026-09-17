<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Events\Billing;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Nvade\Numerosis\Models\Central\Tenant;

/**
 * `usageAmount` is the metered part of the invoice in minor units, null when
 * the invoice carried no usage lines. A metered invoice has no fixed total, so
 * nothing downstream may assume the plan's price.
 */
class PaymentSettled
{
    use Dispatchable;
    use SerializesModels;

    public readonly string $tenantId;

    public function __construct(
        public readonly Tenant $tenant,
        public readonly string|int $ownerId,
        public readonly ?int $usageAmount = null,
        public readonly ?string $currency = null,
    ) {
        $this->tenantId = (string) $tenant->getTenantKey();
    }
}
