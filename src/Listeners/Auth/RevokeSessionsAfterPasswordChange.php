<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Listeners\Auth;

use Nvade\Numerosis\Actions\Auth\RevokeOtherSessions;
use Nvade\Numerosis\Events\Auth\PasswordChanged;

class RevokeSessionsAfterPasswordChange
{
    public function handle(PasswordChanged $event): void
    {
        RevokeOtherSessions::run($event->guard, $event->userId);
    }
}
