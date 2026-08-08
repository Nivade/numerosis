<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Nvade\Numerosis\Models\Tenant\Invitation;
use Nvade\Numerosis\Support\Routes\RouteNames;

class InvitationSent extends Notification
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
        $url = route(RouteNames::invitationShow(), ['token' => $this->invitation->token]);

        return (new MailMessage)
            ->subject('You have been invited to join '.$this->invitation->tenant->id)
            ->greeting('Hello!')
            ->line($this->invitation->inviter->name.' has invited you to join their organization.')
            ->line('Your role will be: '.ucfirst($this->invitation->role))
            ->action('Accept Invitation', $url)
            ->line('This invitation will expire in 7 days.')
            ->line('If you did not expect this invitation, you can ignore this email.');
    }
}
