<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Actions\Tenancy;

use Lorisleiva\Actions\Concerns\AsAction;
use Nvade\Numerosis\Contracts\Tenancy\ProvisionContribution;
use Nvade\Numerosis\Contracts\Tenancy\RequiresContributions;
use Nvade\Numerosis\Data\Tenancy\OwnerContribution;
use Nvade\Numerosis\Enums\Tenancy\MembershipRole;
use Nvade\Numerosis\Models\Central\CentralUser;
use Nvade\Numerosis\Models\Central\TenantProvision;
use Nvade\Numerosis\Numerosis;

/**
 * Attaches the registering user to the tenant as its owner and creates their
 * counterpart row inside the tenant database. It writes the tenant-side row
 * itself because neither `MembershipObserver::created()` nor
 * `Listeners\Tenancy\BackfillTenantUsers` has run by the time
 * `PromoteFirstUserToAdmin` reads the tenant's users.
 */
class AddTenantOwner implements RequiresContributions
{
    use AsAction;

    /**
     * @return list<class-string<ProvisionContribution>>
     */
    public static function requires(): array
    {
        return [OwnerContribution::class];
    }

    public function handle(TenantProvision $provision): void
    {
        $tenant = $provision->tenant()->firstOrFail();
        $centralUserClass = Numerosis::model(CentralUser::class);

        // The runner skips this step when the contribution is absent, so the
        // null branch is unreachable through the pipeline.
        $owner = $provision->contribution(OwnerContribution::class);

        /** @var CentralUser $user */
        $user = $centralUserClass::where('global_id', $owner?->global_id)->firstOrFail();

        if (! $user->tenants()->where('tenants.id', $tenant->id)->exists()) {
            $user->tenants()->attach($tenant, [
                'role' => MembershipRole::Owner,
                'joined_at' => now(),
            ]);
        }

        EnsureTenantUserExists::run($tenant, $user);
    }
}
