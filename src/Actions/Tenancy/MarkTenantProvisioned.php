<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Actions\Tenancy;

use Illuminate\Support\Facades\Cache;
use Lorisleiva\Actions\Concerns\AsAction;
use Nvade\Numerosis\Events\Tenancy\TenantProvisioned;
use Nvade\Numerosis\Models\Central\PendingTenantProvision;
use Nvade\Numerosis\Models\Central\Tenant as CentralTenant;
use Nvade\Numerosis\Support\Numerosis;
use Stancl\Tenancy\Contracts\Tenant;

/**
 * Marks a tenant ready: stamps `provisioned_at`, clears its pending row, and
 * broadcasts {@see TenantProvisioned}.
 *
 * `provisioned_at` — not the existence of the tenant row — is what makes a
 * tenant safe to link to, since the row exists well before its database does.
 */
class MarkTenantProvisioned
{
    use AsAction;

    public function handle(Tenant $tenant): void
    {
        $tenant->update(['provisioned_at' => now()]);

        Numerosis::model(PendingTenantProvision::class)::where('domain', $tenant->getTenantKey())->delete();

        // "Provisioning finished" and "no provisioning chain in flight for
        // this domain" are the same fact.
        Cache::lock("tenant-chain:{$tenant->getTenantKey()}")->forceRelease();

        if (! $tenant instanceof CentralTenant) {
            return;
        }

        $owner = $tenant->owner();

        if ($owner) {
            event(new TenantProvisioned($tenant, $owner->id));
        }
    }
}
