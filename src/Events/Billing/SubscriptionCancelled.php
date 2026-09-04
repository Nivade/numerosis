<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Events\Billing;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Nvade\Numerosis\Models\Central\Tenant;

/**
 * The customer's cancellation. Access is suspended separately, and the two can
 * be days apart; `TenantSuspended` is the enforcement side.
 *
 * `$tenantId` rides alongside `$tenant` because `SerializesModels` re-queries
 * on unserialize: a queued listener running after the tenant is gone gets a
 * `ModelNotFoundException` off the model but can still read the id.
 */
class SubscriptionCancelled
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly Tenant $tenant,
        public readonly ?Carbon $gracePeriodEndsAt,
        public readonly string $tenantId,
    ) {}
}
