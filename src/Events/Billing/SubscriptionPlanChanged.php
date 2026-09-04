<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Events\Billing;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Nvade\Numerosis\Enums\Billing\PlanChangeDirection;
use Nvade\Numerosis\Models\Central\Tenant;

/**
 * A subscription's price actually changed, observed where every change is
 * visible regardless of who made it: the `customer.subscription.updated`
 * webhook. A host calling `Actions\Billing\Subscriptions\SwapSubscriptionPlan`
 * itself, or an edit made in the Stripe dashboard, both arrive here.
 *
 * `$direction` falls back to `Upgrade` when neither price resolves to a known
 * plan — it is a hint for copy and entitlement heuristics, not an
 * authoritative comparison.
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
