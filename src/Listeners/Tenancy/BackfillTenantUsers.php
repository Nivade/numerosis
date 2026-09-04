<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Listeners\Tenancy;

use Illuminate\Contracts\Queue\ShouldQueue;
use Nvade\Numerosis\Actions\Tenancy\EnsureTenantUserExists;
use Nvade\Numerosis\Events\Tenancy\TenantProvisioned;

/**
 * Closes the window where a member was attached before the tenant's database
 * existed: `MembershipObserver::created()` skips row creation for an
 * unprovisioned tenant, so this runs the same action for every existing
 * membership once provisioning finishes. Idempotent via
 * `EnsureTenantUserExists`'s own `firstOrCreate`, so it is harmless to run
 * alongside the observer's own synchronous path or stancl's queued
 * `UpdateSyncedResource` sync.
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
