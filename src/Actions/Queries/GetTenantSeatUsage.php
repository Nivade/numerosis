<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Actions\Queries;

use Lorisleiva\Actions\Concerns\AsAction;
use Nvade\Numerosis\Contracts\Billing\Entitlements;
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

    public function __construct(private readonly Entitlements $entitlements) {}

    public function handle(Tenant $tenant): SeatUsage
    {
        return new SeatUsage(
            members: $tenant->users()->count(),
            pendingInvitations: Numerosis::model(Invitation::class)::query()
                ->pending()
                ->where('tenant_id', $tenant->getKey())
                ->count(),
            limit: $this->entitlements->limit(Entitlements::SEATS, $tenant),
        );
    }
}
