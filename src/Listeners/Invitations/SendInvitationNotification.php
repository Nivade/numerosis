<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Listeners\Invitations;

use Illuminate\Support\Facades\Notification;
use Nvade\Numerosis\Events\Invitations\InvitationIssued;
use Nvade\Numerosis\Features\Invitations\InvitationsFeature;
use Nvade\Numerosis\Notifications\InvitationSent;
use Nvade\Numerosis\Support\Features;

/**
 * Sends the invitation email. Always registered, and checks the invitations
 * feature itself before sending.
 */
class SendInvitationNotification
{
    public function handle(InvitationIssued $event): void
    {
        if (! Features::enabled(InvitationsFeature::NAME)) {
            return;
        }

        Notification::route('mail', $event->invitation->email)
            ->notify(new InvitationSent($event->invitation));
    }
}
