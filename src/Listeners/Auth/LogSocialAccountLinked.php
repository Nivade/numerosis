<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Listeners\Auth;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\Attributes\DeleteWhenMissingModels;
use Nvade\Numerosis\Events\Auth\SocialAccountLinked;

#[DeleteWhenMissingModels]
class LogSocialAccountLinked implements ShouldQueue
{
    public function handle(SocialAccountLinked $event): void
    {
        activity()
            ->causedBy($event->socialAccount->user)
            ->performedOn($event->socialAccount)
            ->withProperties(['provider' => $event->provider])
            ->log("Connected {$event->provider} social account");
    }
}
