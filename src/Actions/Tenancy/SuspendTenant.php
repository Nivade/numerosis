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
        // Suspending a closed tenant would outlive the closure: reopening
        // clears `closed_at` and would leave `suspended_at` behind.
        if ($tenant->isClosed() || $tenant->isSuspended()) {
            return;
        }

        $tenant->update(['suspended_at' => now()]);

        event(new TenantSuspended($tenant));
    }
}
