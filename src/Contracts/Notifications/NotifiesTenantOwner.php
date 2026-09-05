<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Contracts\Notifications;

use Illuminate\Notifications\Notification;
use Nvade\Numerosis\Models\Central\Tenant;

/**
 * Every billing and invitation listener today sends through
 * `$event->tenant->owner()?->notify($notification)`, so "who counts as the
 * tenant's owner" is hardcoded to `Tenant::owner()` at every call site. A
 * consumer with a different ownership model implements this once instead.
 */
interface NotifiesTenantOwner
{
    public function notify(Tenant $tenant, Notification $notification): void;
}
