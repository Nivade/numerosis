<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Actions\Queries;

use Lorisleiva\Actions\Concerns\AsAction;
use Nvade\Numerosis\Data\Billing\SeatUsage;
use Nvade\Numerosis\Models\Central\Invitation;
use Nvade\Numerosis\Models\Central\Tenant;
use Nvade\Numerosis\Numerosis;

/**
 * The one definition of how many seats a tenant occupies, and of the cap its
 * plan puts on them.
 *
 * @method static SeatUsage run(Tenant $tenant)
 */
class GetTenantSeatUsage
{
    use AsAction;

    public function handle(Tenant $tenant): SeatUsage
    {
        return new SeatUsage(
            members: $tenant->users()->count(),
            pendingInvitations: Numerosis::model(Invitation::class)::query()
                ->pending()
                ->where('tenant_id', $tenant->getKey())
                ->count(),
            limit: $this->limitFor($tenant),
        );
    }

    /**
     * Read from the plan, not from {@see Entitlements::limit()}: that memoizes
     * per tenant, and an accept has to see a downgrade written earlier in the
     * same request.
     */
    private function limitFor(Tenant $tenant): ?int
    {
        $subscription = GetActiveSubscription::run($tenant);
        $maxUsers = $subscription?->paymentPlan?->metadata()['options']['max_users'] ?? null;

        return is_numeric($maxUsers) ? (int) $maxUsers : null;
    }
}
