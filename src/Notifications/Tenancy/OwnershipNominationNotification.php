<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Notifications\Tenancy;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\URL;
use Nvade\Numerosis\Enums\Notifications\NotificationType;
use Nvade\Numerosis\Models\Central\OwnershipNomination;
use Nvade\Numerosis\Routing\RouteNames;
use Override;

class OwnershipNominationNotification extends TenantNotification
{
    public function __construct(public OwnershipNomination $nomination)
    {
        parent::__construct($nomination->tenant);
    }

    public function notificationType(): NotificationType
    {
        return NotificationType::OwnershipNominated;
    }

    /**
     * @return array<string, mixed>
     */
    #[Override]
    public function toDatabase(object $notifiable): array
    {
        return $this->payload(
            NotificationType::OwnershipNominated->label(),
            __('You have been offered ownership of :name.', ['name' => (string) $this->tenant->name]),
        );
    }

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
