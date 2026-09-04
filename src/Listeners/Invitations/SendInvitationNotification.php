<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Listeners\Invitations;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\Attributes\Backoff;
use Illuminate\Queue\Attributes\DeleteWhenMissingModels;
use Illuminate\Queue\Attributes\Queue;
use Illuminate\Queue\Attributes\Tries;
use Illuminate\Support\Facades\Notification;
use Nvade\Numerosis\Events\Invitations\InvitationCreated;
use Nvade\Numerosis\Notifications\InvitationNotification;

/**
 * Always registered — always fires, invitations are not behind their own
 * gate the way social login is.
 */
#[Queue('mail')]
#[Tries(3)]
#[Backoff([10, 60, 300])]
#[DeleteWhenMissingModels]
class SendInvitationNotification implements ShouldQueue
{
    public function handle(InvitationCreated $event): void
    {
        Notification::route('mail', $event->invitation->email)
            ->notify(new InvitationNotification($event->invitation));
    }
}
