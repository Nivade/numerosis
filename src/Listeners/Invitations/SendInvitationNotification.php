<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Listeners\Invitations;

use Illuminate\Support\Facades\Notification;
use Nvade\Numerosis\Events\Invitations\InvitationIssued;
use Nvade\Numerosis\Features\Invitations\InvitationsFeature;
use Nvade\Numerosis\Notifications\InvitationSent;
use Nvade\Numerosis\Support\Features;

/**
 * Auto-discovered by Laravel's event discovery, so InvitationsFeature cannot
 * un-discover it — the listener stays registered and resolved regardless of
 * the feature toggle. The early return below is the named exception to
 * "a disabled feature must load nothing" (Ground rules,
 * .claude/plans/opt-in-feature-classes.md): this is deliberate, not a gap.
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
