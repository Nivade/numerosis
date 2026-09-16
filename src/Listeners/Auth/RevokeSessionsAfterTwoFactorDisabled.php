<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Listeners\Auth;

use Illuminate\Support\Facades\Auth;
use Laravel\Fortify\Events\TwoFactorAuthenticationDisabled;
use Nvade\Numerosis\Actions\Auth\RevokeOtherSessions;
use Nvade\Numerosis\Enums\Tenancy\Context;
use Nvade\Numerosis\Models\User;

/**
 * The password is unchanged here, so the stamp `AuthenticateSession` reads
 * still matches on every other device. Deleting the rows is the only
 * revocation available, and a driver that cannot list them keeps them.
 */
class RevokeSessionsAfterTwoFactorDisabled
{
    public function handle(TwoFactorAuthenticationDisabled $event): void
    {
        $user = $event->user;

        if ($user instanceof User) {
            $this->revokeFor($user);
        }
    }

    private function revokeFor(User $user): void
    {
        $guard = Context::Central->guard();
        $identifier = $user->getKey();

        if (! is_int($identifier) && ! is_string($identifier)) {
            return;
        }

        // Staff clearing someone else's factor must not leave that person
        // signed in, and must not revoke its own request's session either.
        $ownRequest = Auth::guard($guard)->id() === $identifier;

        RevokeOtherSessions::run($guard, $identifier, keepCurrent: $ownRequest);
    }
}
