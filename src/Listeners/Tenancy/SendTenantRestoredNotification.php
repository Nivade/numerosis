<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Listeners\Tenancy;

use Nvade\Numerosis\Contracts\Notifications\NotifiesTenantOwner;
use Nvade\Numerosis\Events\Tenancy\TenantRestored;
use Nvade\Numerosis\Features\Billing\BillingNotificationsFeature;
use Nvade\Numerosis\Notifications\Tenancy\TenantRestored as TenantRestoredNotification;
use Nvade\Numerosis\Support\Features;

class SendTenantRestoredNotification
{
    public function handle(TenantRestored $event): void
    {
        if (! Features::enabled(BillingNotificationsFeature::NAME)) {
            return;
        }

        resolve(NotifiesTenantOwner::class)->notify($event->tenant, new TenantRestoredNotification($event->tenant));
    }
}
