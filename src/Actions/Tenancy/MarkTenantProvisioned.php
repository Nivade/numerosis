<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Actions\Tenancy;

use Nvade\Numerosis\Events\Tenancy\TenantProvisioned;
use Nvade\Numerosis\Models\Central\PendingTenantProvision;
use Nvade\Numerosis\Models\Central\Tenant as CentralTenant;
use Illuminate\Support\Facades\Cache;
use Lorisleiva\Actions\Concerns\AsAction;
use Stancl\Tenancy\Contracts\Tenant;

// See .claude/rules/tenant-provisioning.md.
class MarkTenantProvisioned
{
    use AsAction;

    public function handle(Tenant $tenant): void
    {
        $tenant->update(['provisioned_at' => now()]);

        PendingTenantProvision::where('domain', $tenant->getTenantKey())->delete();

        // "Provisioning finished" and "no chain in flight for this domain"
        // are the same fact — see Fix 3 in .claude/rules/tenant-provisioning.md.
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
