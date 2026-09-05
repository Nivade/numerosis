<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Listeners\Billing;

use Nvade\Numerosis\Events\Billing\PaymentSettled;
use Nvade\Numerosis\Listeners\Concerns\NotifiesTenantOwnerWhenEnabled;
use Nvade\Numerosis\Notifications\Billing\PaymentConfirmed;

class SendPaymentConfirmedNotification
{
    use NotifiesTenantOwnerWhenEnabled;

    public function handle(PaymentSettled $event): void
    {
        $this->notifyTenantOwner($event->tenant, new PaymentConfirmed($event->tenant));
    }
}
