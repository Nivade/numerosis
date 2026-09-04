<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Actions\Tenancy;

use Lorisleiva\Actions\Concerns\AsAction;
use Nvade\Numerosis\Models\Central\CentralUser;
use Nvade\Numerosis\Models\Central\Membership;
use Nvade\Numerosis\Models\Central\Tenant;
use Nvade\Numerosis\Support\Numerosis;

/**
 * The tenant-side `User` row is a data invariant this package depends on,
 * not a reaction a host should have to provide. Called from
 * {@see \Nvade\Numerosis\Observers\MembershipObserver::created()} for that
 * reason, rather than from a listener on `Events\Tenancy\MemberJoined`.
 *
 * Only creates the row once the tenant is provisioned, since before that the
 * tenant database may not exist. Two things cover the gap: `AddTenantOwner`
 * calls `EnsureTenantUserExists` itself, because `PromoteFirstUserToAdmin`
 * needs the owner's row before provisioning is marked finished, and
 * `Listeners\Tenancy\BackfillTenantUsers` sweeps any other membership
 * attached during that window.
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

        if ($tenant && $user && $tenant->isProvisioned()) {
            EnsureTenantUserExists::run($tenant, $user);
        }
    }
}
