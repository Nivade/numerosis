<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Listeners\Billing;

use Nvade\Numerosis\Contracts\Notifications\NotifiesTenantOwner;
use Nvade\Numerosis\Events\Billing\PaymentSettled;
use Nvade\Numerosis\Features\Billing\BillingNotificationsFeature;
use Nvade\Numerosis\Notifications\Billing\PaymentConfirmed;
use Nvade\Numerosis\Support\Features;

/**
 * Auto-discovered by Laravel's event discovery, so BillingNotificationsFeature
 * cannot un-discover it — see the feature class's own docblock for the named
 * exception this early return is.
 */
class SendPaymentConfirmedNotification
{
    public function handle(PaymentSettled $event): void
    {
        if (! Features::enabled(BillingNotificationsFeature::NAME)) {
            return;
        }

        resolve(NotifiesTenantOwner::class)->notify($event->tenant, new PaymentConfirmed($event->tenant));
    }
}
