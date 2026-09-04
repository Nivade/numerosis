<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Listeners\Tenancy;

use Illuminate\Contracts\Queue\ShouldQueue;
use Nvade\Numerosis\Actions\Tenancy\EnsureTenantUserExists;
use Nvade\Numerosis\Events\Tenancy\TenantProvisioned;

/**
 * Closes the window where a member was attached before the tenant's database
 * existed, since `MembershipObserver::created()` skips an unprovisioned
 * tenant. Runs the same action for every existing membership once
 * provisioning finishes, and `EnsureTenantUserExists`'s own `firstOrCreate`
 * makes overlapping with any other path harmless.
 */
class BackfillTenantUsers implements ShouldQueue
{
    public function handle(TenantProvisioned $event): void
    {
        foreach ($event->tenant->users as $user) {
            EnsureTenantUserExists::run($event->tenant, $user);
        }
    }
}
