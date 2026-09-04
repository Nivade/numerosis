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
 * Fortify's `AuthenticatedSessionController::destroy()` logs out only
 * `config('fortify.guard')` — one guard, whichever the current domain group
 * registered. `POST /logout` is reachable on both central and tenant
 * domains, so the *other* guard's session survives it unless something ends
 * it too. Registered with an explicit `Event::listen()` in
 * `NumerosisServiceProvider::packageBooted()` — Laravel's listener
 * auto-discovery only scans a host application's `app/Listeners`, never a
 * package's `src/`.
 *
 * Fires before `destroy()` invalidates the session (`SessionGuard::logout()`
 * dispatches `Logout` before clearing state), so acting here still sees a
 * live session to end.
 *
 * Guards each branch with `check()`/`tenancy()->initialized` before ever
 * calling `logout()` on the other guard: `logout()` unconditionally
 * re-dispatches `Logout` even when nothing was logged in, so an unguarded
 * call here would recurse into this same listener. The tenant guard's own
 * model has no connection of its own, so resolving it outside tenant
 * context (via `check()`/`user()`) runs its query against whatever
 * connection is ambient, the central one, hydrating a stranger's row. The
 * central guard's model always carries its own explicit connection, so
 * logging it out from tenant context is safe by comparison.
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
