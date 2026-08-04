<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Listeners\Auth;

use Nvade\Numerosis\Events\Auth\SocialAccountDisconnected;

class LogSocialAccountDisconnected
{
    public function handle(SocialAccountDisconnected $event): void
    {
        activity()
            ->causedBy($event->user)
            ->performedOn($event->user)
            ->withProperties(['provider' => $event->provider])
            ->log("Disconnected {$event->provider} social account");
    }
}
