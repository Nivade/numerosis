<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Actions\Tenancy;

use Lorisleiva\Actions\Concerns\AsAction;
use Nvade\Numerosis\Exceptions\Tenancy\OwnershipNominationUnavailable;
use Nvade\Numerosis\Models\Central\CentralUser;
use Nvade\Numerosis\Models\Central\Membership;
use Nvade\Numerosis\Models\Central\OwnershipNomination;
use Nvade\Numerosis\Models\Central\Tenant;
use Nvade\Numerosis\Numerosis;

/**
 * Claimed with a conditional `UPDATE … WHERE accepted_at IS NULL`, so of two
 * concurrent POSTs the loser matches zero rows and throws.
 *
 * @method static Tenant run(OwnershipNomination $nomination, CentralUser $user)
 */
class AcceptOwnershipNomination
{
    use AsAction;

    public function handle(OwnershipNomination $nomination, CentralUser $user): Tenant
    {
        return $nomination->getConnection()->transaction(function () use ($nomination, $user): Tenant {
            $nomination->assertClaimable();

            throw_unless(
                $nomination->nominee_global_id === $user->global_id,
                OwnershipNominationUnavailable::class,
                'This ownership transfer was offered to a different account.',
            );

            $nominationClass = Numerosis::model(OwnershipNomination::class);

            $claimed = $nominationClass::query()
                ->whereKey($nomination->getKey())
                ->whereNull('accepted_at')
                ->update(['accepted_at' => now()]);

            throw_if($claimed === 0, OwnershipNominationUnavailable::class, 'This ownership transfer has already been accepted.');

            $tenantClass = Numerosis::model(Tenant::class);
            $tenant = $tenantClass::findOrFail($nomination->tenant_id);

            $target = Numerosis::model(Membership::class)::query()
                ->where('tenant_id', $nomination->tenant_id)
                ->where('global_user_id', $nomination->nominee_global_id)
                ->first();

            throw_unless(
                $target instanceof Membership,
                OwnershipNominationUnavailable::class,
                'You are no longer a member of this workspace.',
            );

            TransferTenantOwnership::run($tenant, $target);

            return $tenant;
        });
    }
}
