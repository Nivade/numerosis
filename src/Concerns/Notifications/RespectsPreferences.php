<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Concerns\Notifications;

use Nvade\Numerosis\Contracts\Notifications\NotificationChannels;
use Nvade\Numerosis\Enums\Notifications\NotificationType;

/**
 * `via()` for a notification whose channels are the recipient's business. The
 * implementer declares its type; everything else (defaults, overrides, and the
 * mail nobody may switch off) is the resolver's.
 */
trait RespectsPreferences
{
    abstract public function notificationType(): NotificationType;

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return resolve(NotificationChannels::class)->for($this->notificationType(), $notifiable);
    }
}
