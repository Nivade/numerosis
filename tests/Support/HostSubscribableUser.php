<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Tests\Support;

use Illuminate\Database\Eloquent\Attributes\Table;
use Nvade\Numerosis\Concerns\Billing\Billable;
use Nvade\Numerosis\Concerns\Tenancy\HasGlobalIdentity;
use Nvade\Numerosis\Contracts\Subscribable;
use Nvade\Numerosis\Models\User;
use Stancl\Tenancy\Database\Concerns\ResourceSyncing;

/**
 * A host user that is `Subscribable` but not `BillableUser` — it can hold a
 * subscription and be judged by `PlanPolicy`, without satisfying the Cashier
 * slice checkout needs. `TenantOrUserBillableResolver` used to drop it.
 *
 * @property string|null $stripe_id
 */
#[Table(name: 'users')]
class HostSubscribableUser extends User implements Subscribable
{
    use Billable;
    use HasGlobalIdentity;
    use ResourceSyncing;

    public function getTenantModelName(): string
    {
        return static::class;
    }

    public function getCentralModelName(): string
    {
        return static::class;
    }

    /**
     * @return list<string>
     */
    public function getSyncedAttributeNames(): array
    {
        return ['name', 'email'];
    }
}
