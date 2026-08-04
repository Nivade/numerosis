<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Listeners\Billing;

use Nvade\Numerosis\Actions\Billing\SyncTenantToStripe;
use Nvade\Numerosis\Models\Central\Tenant;
use Stancl\Tenancy\Events\TenantSaved;

class SyncTenantToStripeOnSave
{
    /**
     * Not named `handle()` deliberately — Laravel auto-discovers any
     * `handle*`/`__invoke` method in app/Listeners and would wire this
     * unconditionally, bypassing the `billing.sync.stripe_customer` gate
     * that {@see \Nvade\Numerosis\Providers\BillingServiceProvider::configureStripeSync()}
     * applies before registering it explicitly.
     */
    public function sync(TenantSaved $event): void
    {
        $tenant = $event->tenant;

        if ($tenant instanceof Tenant) {
            SyncTenantToStripe::dispatch($tenant);
        }
    }
}
