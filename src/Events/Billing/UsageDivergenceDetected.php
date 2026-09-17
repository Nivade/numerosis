<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Events\Billing;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Nvade\Numerosis\Models\Central\Tenant;

/**
 * Local counters and Stripe's meter summaries disagree beyond the configured
 * tolerance. Never corrected automatically: whichever side is wrong, silently
 * moving the number is how a customer ends up billed for usage nobody can
 * point at.
 */
class UsageDivergenceDetected
{
    use Dispatchable;
    use SerializesModels;

    public readonly string $tenantId;

    public function __construct(
        public readonly Tenant $tenant,
        public readonly string $eventName,
        public readonly string $periodStart,
        public readonly int $localTotal,
        public readonly int $stripeTotal,
    ) {
        $this->tenantId = (string) $tenant->getTenantKey();
    }
}
