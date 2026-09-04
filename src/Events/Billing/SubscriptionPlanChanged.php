<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Events\Billing;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Nvade\Numerosis\Enums\Billing\PlanChangeDirection;
use Nvade\Numerosis\Models\Central\Tenant;

/**
 * A subscription's price changed, observed at the
 * `customer.subscription.updated` webhook, where every change is visible
 * whoever made it. `$direction` falls back to `Upgrade` when neither price
 * resolves to a known plan, being a hint for copy and entitlement heuristics
 * and never an authoritative comparison.
 */
class SubscriptionPlanChanged
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly Tenant $tenant,
        public readonly ?string $fromPriceId,
        public readonly string $toPriceId,
        public readonly PlanChangeDirection $direction,
        public readonly string $tenantId,
    ) {}
}
