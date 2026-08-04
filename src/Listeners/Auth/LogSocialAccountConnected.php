<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Listeners\Auth;

use Nvade\Numerosis\Events\Auth\SocialAccountConnected;

class LogSocialAccountConnected
{
    public function handle(SocialAccountConnected $event): void
    {
        activity()
            ->causedBy($event->user)
            ->performedOn($event->user)
            ->withProperties(['provider' => $event->socialLogin->provider])
            ->log("Connected {$event->socialLogin->provider} social account");
    }
}
