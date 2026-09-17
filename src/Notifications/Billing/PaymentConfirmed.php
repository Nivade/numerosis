<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Notifications\Billing;

use Illuminate\Notifications\Messages\MailMessage;
use Laravel\Cashier\Cashier;
use Nvade\Numerosis\Enums\Notifications\NotificationType;
use Nvade\Numerosis\Models\Central\Tenant;
use Nvade\Numerosis\Notifications\Tenancy\TenantNotification;

/**
 * Sent on recovery: an async payment (SEPA-via-iDEAL/Bancontact) finally
 * settling, or a suspended tenant's payment method being fixed.
 * Either way, whatever unsettled-payment or suspended banner was showing
 * clears.
 */
class PaymentConfirmed extends TenantNotification
{
    public function __construct(
        Tenant $tenant,
        public readonly ?int $usageAmount = null,
        public readonly ?string $currency = null,
    ) {
        parent::__construct($tenant);
    }

    public function toMail(object $notifiable): MailMessage
    {
        $message = (new MailMessage)
            ->subject("Payment confirmed for {$this->tenant->name}")
            ->greeting('Good news!')
            ->line("Your payment for {$this->tenant->name} has been confirmed.");

        // Named rather than left to be discovered on the invoice: a total that
        // moves every month reads as a billing error when nothing explains it.
        if ($this->usageAmount !== null) {
            $message->line("This invoice included {$this->formattedUsage()} of usage on top of your plan.");
        }

        $unsubscribe = $this->unsubscribeUrl($notifiable);

        $message->line('Everything is back to normal — thanks for your patience.');

        return $unsubscribe === null
            ? $message
            : $message->line("Stop these emails: {$unsubscribe}");
    }

    private function formattedUsage(): string
    {
        return Cashier::formatAmount($this->usageAmount ?? 0, $this->currency);
    }

    public function notificationType(): NotificationType
    {
        return NotificationType::PaymentConfirmed;
    }

    /**
     * @return array<string, mixed>
     */
    public function toDatabase(object $notifiable): array
    {
        return $this->payload(
            NotificationType::PaymentConfirmed->label(),
            __('Your payment for :name has been confirmed.', ['name' => (string) $this->tenant->name]),
        );
    }
}
