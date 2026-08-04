<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Listeners\Billing;

use Nvade\Numerosis\Events\Billing\TenantSuspended;
use Nvade\Numerosis\Features\Billing\BillingNotificationsFeature;
use Nvade\Numerosis\Notifications\Billing\TenantSuspended as TenantSuspendedNotification;
use Nvade\Numerosis\Support\Features;

/**
 * Auto-discovered by Laravel's event discovery, so BillingNotificationsFeature
 * cannot un-discover it — see the feature class's own docblock for the named
 * exception this early return is.
 */
class SendTenantSuspendedNotification
{
    public function handle(TenantSuspended $event): void
    {
        if (! Features::enabled(BillingNotificationsFeature::NAME)) {
            return;
        }

        $event->tenant->owner()?->notify(new TenantSuspendedNotification($event->tenant));
    }
}
