<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Notifications\Tenancy;

use Illuminate\Notifications\Messages\MailMessage;
use Nvade\Numerosis\Enums\Notifications\NotificationType;

/**
 * Sent when a suspended tenant is un-suspended. Restoration is not
 * necessarily a settlement, so this stays a separate notification from
 * `Notifications\Billing\PaymentConfirmed`.
 */
class TenantRestored extends TenantNotification
{
    public function toMail(object $notifiable): MailMessage
    {
        $message = (new MailMessage)
            ->subject("Access to {$this->tenant->name} has been restored")
            ->greeting('Good news!')
            ->line("Access to {$this->tenant->name} has been restored.")
            ->line('Everything is back to normal.');

        $unsubscribe = $this->unsubscribeUrl($notifiable);

        return $unsubscribe === null
            ? $message
            : $message->line("Stop these emails: {$unsubscribe}");
    }

    public function notificationType(): NotificationType
    {
        return NotificationType::TenantRestored;
    }

    /**
     * @return array<string, mixed>
     */
    public function toDatabase(object $notifiable): array
    {
        return $this->payload(
            NotificationType::TenantRestored->label(),
            __(':name is available again.', ['name' => (string) $this->tenant->name]),
        );
    }
}
