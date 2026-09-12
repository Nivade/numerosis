<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Actions\Tenancy;

use Lorisleiva\Actions\Concerns\AsAction;
use Nvade\Numerosis\Events\Billing\TenantSuspended;
use Nvade\Numerosis\Models\Central\Tenant;

class SuspendTenant
{
    use AsAction;

    public function handle(Tenant $tenant): void
    {
        if ($tenant->isSuspended()) {
            return;
        }

        $tenant->update(['suspended_at' => now()]);

        event(new TenantSuspended($tenant, (string) $tenant->getTenantKey()));
    }
}
