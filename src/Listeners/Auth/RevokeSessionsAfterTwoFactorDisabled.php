<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Listeners\Auth;

use Illuminate\Support\Facades\Auth;
use Laravel\Fortify\Events\TwoFactorAuthenticationDisabled;
use Nvade\Numerosis\Actions\Auth\RevokeOtherSessions;
use Nvade\Numerosis\Enums\Tenancy\Context;

/**
 * The password is unchanged here, so the stamp `AuthenticateSession` reads
 * still matches on every other device. Deleting the rows is the only
 * revocation available, and a driver that cannot list them keeps them.
 */
class RevokeSessionsAfterTwoFactorDisabled
{
    public function handle(TwoFactorAuthenticationDisabled $event): void
    {
        $guard = Context::current()->guard();
        $identifier = Auth::guard($guard)->id();

        if (! is_int($identifier) && ! is_string($identifier)) {
            return;
        }

        RevokeOtherSessions::run($guard, $identifier);
    }
}
