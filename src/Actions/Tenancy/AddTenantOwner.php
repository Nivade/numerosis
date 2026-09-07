<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Actions\Tenancy;

use Lorisleiva\Actions\Concerns\AsAction;
use Nvade\Numerosis\Data\Tenancy\TenantProvisionData;
use Nvade\Numerosis\Enums\Tenancy\MembershipRole;
use Nvade\Numerosis\Models\Central\CentralUser;
use Nvade\Numerosis\Models\Central\Tenant;
use Nvade\Numerosis\Numerosis;

/**
 * Attaches the registering user to the tenant as its owner and creates their
 * counterpart row inside the tenant database. A provisioning step, idempotent
 * on retry. It writes the tenant-side row itself because neither
 * `MembershipObserver::created()` nor `Listeners\Tenancy\BackfillTenantUsers`
 * has run when `PromoteFirstUserToAdmin` reads the tenant's users.
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
                'role' => MembershipRole::Owner,
                'joined_at' => now(),
            ]);
        }

        // Outside the guard above: a retried provision can find the pivot
        // already written and the tenant-side row still missing.
        EnsureTenantUserExists::run($tenant, $user);
    }
}
