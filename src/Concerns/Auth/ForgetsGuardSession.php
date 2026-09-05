<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Concerns\Auth;

use Illuminate\Auth\SessionGuard;
use Illuminate\Contracts\Auth\Guard;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\Session;

/**
 * Drops a session guard's stored id and remember cookie without resolving its
 * user. `SessionGuard::logout()` resolves the current user first, and outside
 * tenant context that lookup runs against the central database and writes a
 * remember token onto a stranger's row.
 */
trait ForgetsGuardSession
{
    protected function forgetGuardSession(Guard $guard): void
    {
        if (! $guard instanceof SessionGuard) {
            return;
        }

        Session::forget($guard->getName());
        Cookie::queue(Cookie::forget($guard->getRecallerName()));
    }
}
