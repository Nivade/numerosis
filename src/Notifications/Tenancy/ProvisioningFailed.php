<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Notifications\Tenancy;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Nvade\Numerosis\Concerns\Notifications\RespectsPreferences;
use Nvade\Numerosis\Enums\Notifications\NotificationType;

/**
 * Operator-facing, so it names the tenant: whoever receives it already
 * administers the installation. The health endpoint is the anonymous view of
 * the same failure.
 */
class ProvisioningFailed extends Notification
{
    use Queueable;
    use RespectsPreferences;

    public function __construct(
        public readonly string $slug,
        public readonly ?string $step,
        public readonly ?string $error,
        public readonly int $failedLastHour,
    ) {}

    public function notificationType(): NotificationType
    {
        return NotificationType::ProvisioningFailed;
    }

    public function toMail(object $notifiable): MailMessage
    {
        $message = (new MailMessage)
            ->error()
            ->subject("Tenant provisioning failed: {$this->slug}")
            ->line("Provisioning for [{$this->slug}] stopped and the chain will not resume on its own.");

        if ($this->step !== null) {
            $message->line("Failing step: {$this->step}");
        }

        if ($this->error !== null) {
            $message->line("Error: {$this->error}");
        }

        return $message
            ->line("Provisions failed in the last hour: {$this->failedLastHour}")
            ->line('Further failures are suppressed for the configured throttle window.');
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'slug' => $this->slug,
            'step' => $this->step,
            'error' => $this->error,
            'failed_last_hour' => $this->failedLastHour,
        ];
    }
}
