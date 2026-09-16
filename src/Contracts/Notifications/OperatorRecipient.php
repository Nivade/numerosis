<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Contracts\Notifications;

use Illuminate\Notifications\Notification;

/**
 * Whoever this installation tells about a failure no customer reports. Bind
 * your own to reach Slack, PagerDuty or an on-call rota; the shipped one
 * mails `numerosis.notifications.operator` and does nothing when it is unset.
 */
interface OperatorRecipient
{
    public function notify(Notification $notification): void;
}
