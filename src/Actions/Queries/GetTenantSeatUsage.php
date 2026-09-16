<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Actions\Queries;

use Lorisleiva\Actions\Concerns\AsAction;
use Nvade\Numerosis\Data\Billing\SeatUsage;
use Nvade\Numerosis\Models\Central\Invitation;
use Nvade\Numerosis\Models\Central\Subscription;
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
     * `options.max_users` comes from host-editable config, so a non-numeric
     * value is uncapped exactly as an absent one is: a malformed entry must
     * not lock a customer out of seats they are paying for.
     */
    private function limitFor(Tenant $tenant): ?int
    {
        $subscription = $tenant->subscriptions()->get()
            ->first(fn (Subscription $subscription): bool => $subscription->valid());

        $maxUsers = $subscription?->paymentPlan?->metadata()['options']['max_users'] ?? null;

        return is_numeric($maxUsers) ? (int) $maxUsers : null;
    }
}
