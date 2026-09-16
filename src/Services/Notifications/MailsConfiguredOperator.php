<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Services\Notifications;

use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Notification as Notifications;
use Nvade\Numerosis\Contracts\Notifications\OperatorRecipient;

class MailsConfiguredOperator implements OperatorRecipient
{
    public function notify(Notification $notification): void
    {
        $operator = Config::get('numerosis.notifications.operator');

        if (! is_string($operator) || $operator === '') {
            return;
        }

        Notifications::route('mail', $operator)->notify($notification);
    }
}
