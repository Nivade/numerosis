<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Services\Notifications;

use Illuminate\Notifications\Notification;
use Nvade\Numerosis\Contracts\Notifications\NotifiesTenantOwner;
use Nvade\Numerosis\Contracts\Tenancy\HasTenantOwner;

class NotifiesTenantOwnerDirectly implements NotifiesTenantOwner
{
    public function notify(HasTenantOwner $tenant, Notification $notification): void
    {
        $tenant->owner()?->notify($notification);
    }
}
