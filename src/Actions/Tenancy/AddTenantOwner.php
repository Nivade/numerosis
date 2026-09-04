<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Actions\Tenancy;

use Lorisleiva\Actions\Concerns\AsAction;
use Nvade\Numerosis\Data\Tenancy\TenantProvisionData;
use Nvade\Numerosis\Models\Central\CentralUser;
use Nvade\Numerosis\Models\Central\Tenant;
use Nvade\Numerosis\Support\Numerosis;

/**
 * Attaches the registering user to the tenant as its owner, and creates
 * their counterpart row inside the tenant database.
 *
 * A provisioning step, idempotent so a retried provision is harmless.
 *
 * The tenant-side row is written here rather than left to
 * `MembershipObserver::created()`, which skips a tenant that has no
 * `provisioned_at` yet — every membership attached during provisioning is in
 * that state. `Listeners\Tenancy\BackfillTenantUsers` does not cover it
 * either: it runs off `TenantProvisioned`, which `MarkTenantProvisioned`
 * dispatches *after* `PromoteFirstUserToAdmin` has already read the tenant's
 * users (see `FinalizeTenantProvisioning::handle()`).
 */
class AddTenantOwner
{
    use AsAction;

    public function handle(Tenant $tenant, TenantProvisionData $data): void
    {
        $centralUserClass = Numerosis::model(CentralUser::class);

        /** @var CentralUser $user */
        $user = $centralUserClass::where('global_id', $data->registration->global_id)->firstOrFail();

        if (! $user->tenants()->where('tenants.id', $tenant->id)->exists()) {
            $user->tenants()->attach($tenant, [
                'role' => 'owner',
                'joined_at' => now(),
            ]);
        }

        // Outside the guard above: a retried provision can find the pivot
        // already written and the tenant-side row still missing.
        EnsureTenantUserExists::run($tenant, $user);
    }
}
