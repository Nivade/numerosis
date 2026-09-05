<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Listeners\Billing;

use Nvade\Numerosis\Events\Billing\PaymentFailed;
use Nvade\Numerosis\Listeners\Concerns\NotifiesTenantOwnerWhenEnabled;
use Nvade\Numerosis\Notifications\Billing\PaymentFailed as PaymentFailedNotification;

class SendPaymentFailedNotification
{
    use NotifiesTenantOwnerWhenEnabled;

    public function handle(PaymentFailed $event): void
    {
        if ($event->tenant->isSuspended()) {
            return;
        }

        $this->notifyTenantOwner($event->tenant, new PaymentFailedNotification($event->tenant));
    }
}
