<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Observers;

use Nvade\Numerosis\Actions\Cache\ForgetUserTenants;
use Nvade\Numerosis\Models\Central\Tenant;

/**
 * Runs on `deleting`, not `deleted` — the tenant's membership pivot rows
 * (and the users list they resolve here) are still readable at that point,
 * but gone by the time `deleted` would fire. See
 * {@see \Nvade\Numerosis\Models\User::canAccessTenant()}, which needs the user's tenant
 * list to no longer include a tenant right after it's deleted.
 */
class TenantObserver
{
    public function deleting(Tenant $tenant): void
    {
        /** @var \Illuminate\Support\Collection<int, string> $globalIds */
        $globalIds = $tenant->users()->pluck('global_id');

        $globalIds->each(fn (string $globalId) => ForgetUserTenants::run($globalId));
    }
}
