<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Listeners\Tenancy;

use Nvade\Numerosis\Events\Tenancy\TenantRestored;
use Nvade\Numerosis\Listeners\Concerns\NotifiesTenantOwnerWhenEnabled;
use Nvade\Numerosis\Notifications\Tenancy\TenantRestored as TenantRestoredNotification;

class SendTenantRestoredNotification
{
    use NotifiesTenantOwnerWhenEnabled;

    public function handle(TenantRestored $event): void
    {
        $this->notifyTenantOwner($event->tenant, new TenantRestoredNotification($event->tenant));
    }
}
