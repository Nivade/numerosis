<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Actions\Tenancy;

use Lorisleiva\Actions\Concerns\AsAction;
use Nvade\Numerosis\Models\Central\CentralUser;
use Nvade\Numerosis\Models\Central\Membership;
use Nvade\Numerosis\Models\Central\Tenant;
use Nvade\Numerosis\Numerosis;

/**
 * Creates the tenant-side `User` row for a new membership, but only once the
 * tenant is provisioned, since before that its database may not exist.
 * `AddTenantOwner` and `Listeners\Tenancy\BackfillTenantUsers` cover that gap.
 *
 * @see \Nvade\Numerosis\Observers\Tenancy\MembershipObserver::created()
 */
class SyncTenantUserForMembership
{
    use AsAction;

    public function handle(Membership $membership): void
    {
        $tenantClass = Numerosis::model(Tenant::class);
        $centralUserClass = Numerosis::model(CentralUser::class);

        /** @var Tenant|null $tenant */
        $tenant = $tenantClass::find($membership->tenant_id);
        /** @var CentralUser|null $user */
        $user = $centralUserClass::where('global_id', $membership->global_user_id)->first();

        if ($tenant !== null && $user !== null && $tenant->isProvisioned()) {
            EnsureTenantUserExists::run($tenant, $user);
        }
    }
}
