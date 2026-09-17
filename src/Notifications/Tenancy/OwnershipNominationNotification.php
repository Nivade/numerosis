<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Notifications\Tenancy;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\URL;
use Nvade\Numerosis\Concerns\Notifications\RespectsPreferences;
use Nvade\Numerosis\Enums\Notifications\NotificationType;
use Nvade\Numerosis\Models\Central\OwnershipNomination;
use Nvade\Numerosis\Routing\RouteNames;

class OwnershipNominationNotification extends Notification
{
    use Queueable;
    use RespectsPreferences;

    public function notificationType(): NotificationType
    {
        return NotificationType::OwnershipNominated;
    }

    /**
     * @return array<string, mixed>
     */
    public function toDatabase(object $notifiable): array
    {
        return [
            'type' => NotificationType::OwnershipNominated->value,
            'title' => NotificationType::OwnershipNominated->label(),
            'body' => __('You have been offered ownership of :name.', ['name' => (string) $this->nomination->tenant->name]),
            'action_url' => null,
            'tenant_id' => $this->nomination->tenant_id,
            'tenant_name' => $this->nomination->tenant->name,
        ];
    }

    public function __construct(
        public OwnershipNomination $nomination
    ) {}

    public function toMail(object $notifiable): MailMessage
    {
        $url = URL::temporarySignedRoute(
            RouteNames::ownershipNominationShow(),
            $this->nomination->expires_at,
            ['nomination' => $this->nomination->getRouteKey()],
        );

        $tenantName = $this->nomination->tenant->name;

        return (new MailMessage)
            ->subject(__('You have been asked to take over :tenant', ['tenant' => $tenantName]))
            ->greeting(__('Hello!'))
            ->line(__('You have been nominated as the new owner of :tenant.', ['tenant' => $tenantName]))
            ->line(__('Accepting makes you responsible for its subscription and its billing details.'))
            ->action(__('Accept ownership'), $url)
            ->line(__('This nomination expires :when.', ['when' => $this->nomination->expires_at->diffForHumans()]))
            ->line(__('If you did not expect this, you can ignore this email.'));
    }
}
