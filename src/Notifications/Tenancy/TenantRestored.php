<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Notifications\Tenancy;

use Illuminate\Notifications\Messages\MailMessage;
use Nvade\Numerosis\Notifications\TenantNotification;

/**
 * Sent when a suspended tenant is un-suspended. Restoration is not
 * necessarily a settlement, so this stays a separate notification from
 * `Notifications\Billing\PaymentConfirmed`.
 */
class TenantRestored extends TenantNotification
{
    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("Access to {$this->tenant->name} has been restored")
            ->greeting('Good news!')
            ->line("Access to {$this->tenant->name} has been restored.")
            ->line('Everything is back to normal.');
    }
}
