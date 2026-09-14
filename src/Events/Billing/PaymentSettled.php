<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Events\Billing;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Nvade\Numerosis\Models\Central\Tenant;

/**
 * Fired when an asynchronous payment finally settles, from
 * `invoice.payment_succeeded`. A suspended tenant coming back is
 * {@see \Nvade\Numerosis\Events\Tenancy\TenantRestored} instead.
 */
class PaymentSettled
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly Tenant $tenant,
        public readonly string|int $ownerId,
        public readonly string $tenantId,
    ) {}
}
