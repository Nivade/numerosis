<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Actions\Tenancy;

use Lorisleiva\Actions\Concerns\AsAction;
use Nvade\Numerosis\Contracts\Tenancy\ProvisioningStep;
use Nvade\Numerosis\Enums\Tenancy\MembershipRole;
use Nvade\Numerosis\Models\Central\CentralUser;
use Nvade\Numerosis\Models\Central\Tenant;
use Nvade\Numerosis\Models\Central\TenantProvision;
use Nvade\Numerosis\Numerosis;

/**
 * Attaches the registering user to the tenant as its owner and creates their
 * counterpart row inside the tenant database. It writes the tenant-side row
 * itself because neither `MembershipObserver::created()` nor
 * `Listeners\Tenancy\BackfillTenantUsers` has run by the time
 * `PromoteFirstUserToAdmin` reads the tenant's users.
 */
class AddTenantOwner implements ProvisioningStep
{
    use AsAction;

    public function handle(TenantProvision $provision): void
    {
        $tenant = Numerosis::model(Tenant::class)::findOrFail($provision->slug);
        $centralUserClass = Numerosis::model(CentralUser::class);

        /** @var CentralUser $user */
        $user = $centralUserClass::where('global_id', $provision->global_id)->firstOrFail();

        if (! $user->tenants()->where('tenants.id', $tenant->id)->exists()) {
            $user->tenants()->attach($tenant, [
                'role' => MembershipRole::Owner,
                'joined_at' => now(),
            ]);
        }

        EnsureTenantUserExists::run($tenant, $user);
    }
}
