<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Listeners\Billing;

use Nvade\Numerosis\Contracts\Notifications\NotifiesTenantOwner;
use Nvade\Numerosis\Events\Billing\PaymentFailed;
use Nvade\Numerosis\Features\Billing\BillingNotificationsFeature;
use Nvade\Numerosis\Notifications\Billing\PaymentFailed as PaymentFailedNotification;
use Nvade\Numerosis\Support\Features;

/**
 * Auto-discovered by Laravel's event discovery, so BillingNotificationsFeature
 * cannot un-discover it. The feature class's own docblock names this early
 * return as the exception it is.
 */
class SendPaymentFailedNotification
{
    public function handle(PaymentFailed $event): void
    {
        if (! Features::enabled(BillingNotificationsFeature::NAME)) {
            return;
        }

        if (! $event->tenant->isSuspended()) {
            resolve(NotifiesTenantOwner::class)->notify($event->tenant, new PaymentFailedNotification($event->tenant));
        }
    }
}
