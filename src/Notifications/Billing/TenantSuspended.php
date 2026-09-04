<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Notifications\Billing;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Nvade\Numerosis\Models\Central\Tenant;

/**
 * Access revoked, data retained. Sent once suspension actually happens;
 * PaymentFailed is the earlier warning during the grace period.
 */
class TenantSuspended extends Notification
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

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("Access to {$this->tenant->name} has been paused")
            ->greeting('Hello!')
            ->line("Access to {$this->tenant->name} has been paused because we couldn't collect payment.")
            ->line('Your data has not been deleted — fixing your payment method restores access immediately.')
            ->action('Update payment method', route('billing-portal'))
            ->line('If this workspace stays unpaid, it will eventually be deleted — see your billing settings for details.');
    }
}
