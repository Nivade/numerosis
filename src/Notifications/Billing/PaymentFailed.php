<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Notifications\Billing;

use Illuminate\Notifications\Messages\MailMessage;
use Nvade\Numerosis\Enums\Notifications\NotificationType;
use Nvade\Numerosis\Notifications\Tenancy\TenantNotification;

/**
 * Dunning notice sent while the tenant is still in the grace period.
 * Suspension has not happened yet; this is the chance to avoid it.
 */
class PaymentFailed extends TenantNotification
{
    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("Payment failed for {$this->tenant->name}")
            ->greeting('Hello!')
            ->line("We couldn't process the latest payment for {$this->tenant->name}.")
            ->line('Please update your payment method to avoid interruption to your workspace.')
            ->action('Update payment method', route('billing-portal'))
            ->line("If this isn't fixed soon, access to {$this->tenant->name} will be paused until it is.");
    }

    public function notificationType(): NotificationType
    {
        return NotificationType::PaymentFailed;
    }

    /**
     * @return array<string, mixed>
     */
    public function toDatabase(object $notifiable): array
    {
        return $this->payload(
            NotificationType::PaymentFailed->label(),
            __("We couldn't collect the latest payment for :name.", ['name' => (string) $this->tenant->name]),
            route('billing-portal'),
        );
    }
}
