<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Notifications\Billing;

use Nvade\Numerosis\Models\Central\Tenant;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Sent on recovery — either an async payment (SEPA-via-iDEAL/Bancontact)
 * finally settling, or a suspended tenant's payment method being fixed.
 * Either way, whatever AwaitingPayment/suspended banner was showing clears.
 */
class PaymentConfirmed extends Notification
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
            ->subject("Payment confirmed for {$this->tenant->name}")
            ->greeting('Good news!')
            ->line("Your payment for {$this->tenant->name} has been confirmed.")
            ->line('Everything is back to normal — thanks for your patience.');
    }
}
