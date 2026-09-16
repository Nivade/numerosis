<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Actions\Tenancy;

use Lorisleiva\Actions\Concerns\AsAction;
use Nvade\Numerosis\Events\Tenancy\TenantRestored;
use Nvade\Numerosis\Models\Central\CentralUser;
use Nvade\Numerosis\Models\Central\Tenant;

class RestoreTenant
{
    use AsAction;

    public function handle(Tenant $tenant): void
    {
        // A closed tenant's subscription still emits `updated` events while it
        // runs out its period, and none of them mean "let them back in".
        if ($tenant->isClosed() || ! $tenant->isSuspended()) {
            return;
        }

        $tenant->update(['suspended_at' => null]);

        $owner = $tenant->owner();

        if ($owner instanceof CentralUser) {
            event(new TenantRestored($tenant, $owner->id, (string) $tenant->getTenantKey()));
        }
    }
}
