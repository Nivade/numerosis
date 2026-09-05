<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Listeners\Auth;

use Illuminate\Contracts\Queue\ShouldQueue;
use Nvade\Numerosis\Events\Auth\SocialAccountUnlinked;

/**
 * The row is already gone by the time this runs, so nothing is `performedOn()`.
 */
class LogSocialAccountUnlinked implements ShouldQueue
{
    public function handle(SocialAccountUnlinked $event): void
    {
        activity()
            ->withProperties(['provider' => $event->provider, 'global_user_id' => $event->globalUserId])
            ->log("Disconnected {$event->provider} social account");
    }
}
