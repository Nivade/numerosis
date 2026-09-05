<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Listeners\Billing;

use Nvade\Numerosis\Events\Billing\TenantSuspended;
use Nvade\Numerosis\Listeners\Concerns\NotifiesTenantOwnerWhenEnabled;
use Nvade\Numerosis\Notifications\Billing\TenantSuspended as TenantSuspendedNotification;

class SendTenantSuspendedNotification
{
    use NotifiesTenantOwnerWhenEnabled;

    public function handle(TenantSuspended $event): void
    {
        $this->notifyTenantOwner($event->tenant, new TenantSuspendedNotification($event->tenant));
    }
}
