<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Actions\Auth;

use Illuminate\Auth\SessionGuard;
use Illuminate\Contracts\Auth\Guard;
use Illuminate\Contracts\Auth\StatefulGuard;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\Session;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * Logs a user out of both guards directly, for callers outside HTTP's
 * `POST /logout` (`Livewire\Actions\Logout`, tests). That route runs Fortify's
 * `AuthenticatedSessionController::destroy()` and
 * `Listeners\Auth\EndOtherGuardSession`, which between them already invalidate
 * the session, so nothing there may call this too.
 */
class LogoutUser
{
    use AsAction;

    public function handle(): void
    {
        $this->endTenantSession();
        $this->central()->logout();
        Session::invalidate();
    }

    /**
     * Ends the tenant guard's session without ever resolving a user outside
     * tenant context. `SessionGuard::logout()` resolves the current user
     * first, and with tenancy uninitialized that lookup runs against the
     * central database and writes a remember token onto a stranger's row.
     *
     * @see \Nvade\Numerosis\Http\Middleware\EnsureSessionMatchesTenant
     */
    private function endTenantSession(): void
    {
        $guard = $this->tenant();

        if (tenancy()->initialized) {
            $guard->logout();

            return;
        }

        if ($guard instanceof SessionGuard) {
            Session::forget($guard->getName());
            Cookie::queue(Cookie::forget($guard->getRecallerName()));
        }
    }

    public function tenant(): Guard|StatefulGuard
    {
        return Auth::guard(Config::string('numerosis.auth.guards.tenant'));
    }

    public function central(): Guard|StatefulGuard
    {
        return Auth::guard(Config::string('numerosis.auth.guards.central'));
    }
}
