<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Actions\Tenancy;

use Illuminate\Support\Str;
use Lorisleiva\Actions\Concerns\AsAction;
use Nvade\Numerosis\Models\Central\CentralUser;
use Nvade\Numerosis\Models\Central\Membership;
use Nvade\Numerosis\Models\Central\OwnershipNomination;
use Nvade\Numerosis\Models\Central\Tenant;
use Nvade\Numerosis\Notifications\Tenancy\OwnershipNominationNotification;
use Nvade\Numerosis\Numerosis;

/**
 * A tenant holds one nomination at a time, so re-nominating overwrites the
 * row. The ULID is minted again with it, which kills the link already sent to
 * whoever was nominated before.
 *
 * @method static OwnershipNomination run(Tenant $tenant, Membership $target, ?string $nominatedBy = null)
 */
class NominateTenantOwner
{
    use AsAction;

    public function handle(Tenant $tenant, Membership $target, ?string $nominatedBy = null): OwnershipNomination
    {
        AssertOwnershipTransferable::run($tenant, $target);

        $nominationClass = Numerosis::model(OwnershipNomination::class);

        /** @var OwnershipNomination $nomination */
        $nomination = $nominationClass::firstOrNew(['tenant_id' => $tenant->getKey()]);

        $nomination->forceFill([
            'ulid' => (string) Str::ulid(),
            'nominee_global_id' => $target->global_user_id,
            'nominated_by' => $nominatedBy,
            'expires_at' => now()->addHours(72),
            'accepted_at' => null,
        ])->save();

        $nominee = Numerosis::model(CentralUser::class)::where('global_id', $target->global_user_id)->first();

        $nominee?->notify(new OwnershipNominationNotification($nomination));

        return $nomination;
    }
}
