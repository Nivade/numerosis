<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Actions\Tenancy;

use Nvade\Numerosis\Events\Billing\TenantSuspended;
use Nvade\Numerosis\Models\Central\Tenant;
use Lorisleiva\Actions\Concerns\AsAction;

class SuspendTenant
{
    use AsAction;

    public function handle(Tenant $tenant): void
    {
        if ($tenant->isSuspended()) {
            return;
        }

        $tenant->update(['suspended_at' => now()]);

        event(new TenantSuspended($tenant));
    }
}
