<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Observers;

use Illuminate\Support\Collection;
use Nvade\Numerosis\Actions\Cache\ForgetUserTenants;
use Nvade\Numerosis\Models\Central\Tenant;

/**
 * Clears each member's cached tenant list when a tenant is deleted.
 *
 * Runs on `deleting`, not `deleted`: the membership rows naming those users
 * are still readable then, and gone afterwards.
 */
class TenantObserver
{
    public function deleting(Tenant $tenant): void
    {
        /** @var Collection<int, string> $globalIds */
        $globalIds = $tenant->users()->pluck('global_id');

        $globalIds->each(fn (string $globalId) => ForgetUserTenants::run($globalId));
    }
}
