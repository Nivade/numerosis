<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Listeners\Billing;

use Nvade\Numerosis\Events\Billing\PaymentFailed;
use Nvade\Numerosis\Features\Billing\BillingNotificationsFeature;
use Nvade\Numerosis\Notifications\Billing\PaymentFailed as PaymentFailedNotification;
use Nvade\Numerosis\Support\Features;

/**
 * Auto-discovered by Laravel's event discovery, so BillingNotificationsFeature
 * cannot un-discover it — see the feature class's own docblock for the named
 * exception this early return is.
 */
class SendPaymentFailedNotification
{
    public function handle(PaymentFailed $event): void
    {
        if (! Features::enabled(BillingNotificationsFeature::NAME)) {
            return;
        }

        if (! $event->tenant->isSuspended()) {
            $event->tenant->owner()?->notify(new PaymentFailedNotification($event->tenant));
        }
    }
}
