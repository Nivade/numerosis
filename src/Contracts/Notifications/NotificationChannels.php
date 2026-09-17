<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Contracts\Notifications;

use Nvade\Numerosis\Enums\Notifications\NotificationType;

/**
 * Which channels one notification takes for one recipient. Bound so a host can
 * add a channel of its own — Slack, push — without editing a notification class.
 */
interface NotificationChannels
{
    /**
     * @return list<string>
     */
    public function for(NotificationType $type, object $notifiable): array;
}
