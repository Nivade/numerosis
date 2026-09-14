<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Actions\Tenancy;

use Lorisleiva\Actions\Concerns\AsAction;
use Nvade\Numerosis\Enums\Tenancy\TenantProvisionStatus;
use Nvade\Numerosis\Events\Tenancy\TenantProvisioned;
use Nvade\Numerosis\Models\Central\CentralUser;
use Nvade\Numerosis\Models\Central\Tenant as CentralTenant;
use Nvade\Numerosis\Models\Central\TenantProvision;
use Nvade\Numerosis\Numerosis;
use Stancl\Tenancy\Contracts\Tenant;

/**
 * Marks a tenant ready: stamps `provisioned_at`, completes its provision row,
 * and dispatches {@see TenantProvisioned}.
 *
 * `provisioned_at` is what makes a tenant safe to link to, never the existence
 * of the tenant row, which appears well before its database does.
 */
class MarkTenantProvisioned
{
    use AsAction;

    public function handle(Tenant $tenant): void
    {
        $tenant->update(['provisioned_at' => now()]);

        Numerosis::model(TenantProvision::class)::where('slug', $tenant->getTenantKey())->update([
            'status' => TenantProvisionStatus::Completed,
            'completed_at' => now(),
        ]);

        if (! $tenant instanceof CentralTenant) {
            return;
        }

        $owner = $tenant->owner();

        if ($owner instanceof CentralUser) {
            event(new TenantProvisioned($tenant, $owner->id, (string) $tenant->getTenantKey()));
        }
    }
}
