<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Contracts\Notifications;

use Illuminate\Notifications\Notification;
use Nvade\Numerosis\Models\Central\Tenant;

/**
 * Every billing/invitation listener today sends via
 * `$event->tenant->owner()?->notify($notification)` directly — "who counts
 * as the tenant's owner" is therefore hardcoded to `Tenant::owner()` at
 * every call site. A consumer with a different ownership model (multiple
 * notified admins, a role other than "owner") implements this once instead
 * of that assumption being repeated in every listener.
 */
interface NotifiesTenantOwner
{
    public function notify(Tenant $tenant, Notification $notification): void;
}
