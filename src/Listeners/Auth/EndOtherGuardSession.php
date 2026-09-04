<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Listeners\Auth;

use Illuminate\Auth\Events\Logout;
use Illuminate\Auth\SessionGuard;
use Illuminate\Contracts\Auth\StatefulGuard;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\Session;

/**
 * Ends the session of whichever guard `POST /logout` did not log out itself.
 * Fortify's `AuthenticatedSessionController::destroy()` handles only
 * `config('fortify.guard')`, and that route is reachable on both central and
 * tenant domains. Each branch is guarded by `check()`/`tenancy()->initialized`,
 * because `logout()` re-dispatches `Logout` even when nothing was logged in.
 */
class EndOtherGuardSession
{
    public function handle(Logout $event): void
    {
        $central = Config::string('numerosis.auth.guards.central');
        $tenant = Config::string('numerosis.auth.guards.tenant');

        if ($event->guard === $tenant) {
            $this->logoutGuardIfActive($central);

            return;
        }

        if ($event->guard === $central) {
            $this->endTenantSession($tenant);
        }
    }

    private function logoutGuardIfActive(string $guardName): void
    {
        $guard = Auth::guard($guardName);

        if ($guard instanceof StatefulGuard && $guard->check()) {
            $guard->logout();
        }
    }

    private function endTenantSession(string $guardName): void
    {
        $guard = Auth::guard($guardName);

        if (tenancy()->initialized) {
            $this->logoutGuardIfActive($guardName);

            return;
        }

        if ($guard instanceof SessionGuard) {
            Session::forget($guard->getName());
            Cookie::queue(Cookie::forget($guard->getRecallerName()));
        }
    }
}
