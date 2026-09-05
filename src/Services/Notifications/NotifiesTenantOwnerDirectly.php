<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Services\Notifications;

use Illuminate\Notifications\Notification;
use Nvade\Numerosis\Contracts\Notifications\NotifiesTenantOwner;
use Nvade\Numerosis\Models\Central\Tenant;

class NotifiesTenantOwnerDirectly implements NotifiesTenantOwner
{
    public function notify(Tenant $tenant, Notification $notification): void
    {
        $tenant->owner()?->notify($notification);
    }
}
