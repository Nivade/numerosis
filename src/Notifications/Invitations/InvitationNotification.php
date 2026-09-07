<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Notifications\Invitations;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\URL;
use Nvade\Numerosis\Models\Central\Invitation;
use Nvade\Numerosis\Support\Routes\RouteNames;

/**
 * Routed on-demand (`->route('mail', $invitation->email)`), since the
 * invitee is not a `User` yet.
 */
class InvitationNotification extends Notification
{
    use Queueable;

    public function __construct(
        public Invitation $invitation
    ) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $url = URL::temporarySignedRoute(
            RouteNames::invitationShow(),
            $this->invitation->expires_at,
            ['invitation' => $this->invitation->getRouteKey()],
        );

        $tenantName = $this->invitation->tenant->name;

        return (new MailMessage)
            ->subject(__('You have been invited to join :tenant', ['tenant' => $tenantName]))
            ->greeting(__('Hello!'))
            ->line(__('You have been invited to join :tenant.', ['tenant' => $tenantName]))
            ->line(__('Your role will be :role.', ['role' => $this->invitation->role->label()]))
            ->action(__('Accept Invitation'), $url)
            ->line(__('This invitation expires :when.', ['when' => $this->invitation->expires_at->diffForHumans()]))
            ->line(__('If you did not expect this invitation, you can ignore this email.'));
    }
}
