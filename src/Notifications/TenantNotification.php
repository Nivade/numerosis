<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;
use Nvade\Numerosis\Models\Central\Tenant;

/**
 * A mail notification about one tenant, delivered to whoever
 * {@see \Nvade\Numerosis\Contracts\Notifications\NotifiesTenantOwner} routes
 * it to. Subclasses supply `toMail()` and nothing else.
 */
abstract class TenantNotification extends Notification
{
    use Queueable;

    public function __construct(public Tenant $tenant) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }
}
