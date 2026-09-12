<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Notifications\Billing;

use Illuminate\Notifications\Messages\MailMessage;
use Nvade\Numerosis\Notifications\Tenancy\TenantNotification;

/**
 * Sent on recovery: an async payment (SEPA-via-iDEAL/Bancontact) finally
 * settling, or a suspended tenant's payment method being fixed.
 * Either way, whatever unsettled-payment or suspended banner was showing
 * clears.
 */
class PaymentConfirmed extends TenantNotification
{
    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("Payment confirmed for {$this->tenant->name}")
            ->greeting('Good news!')
            ->line("Your payment for {$this->tenant->name} has been confirmed.")
            ->line('Everything is back to normal — thanks for your patience.');
    }
}
