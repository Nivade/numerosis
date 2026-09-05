<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Actions\Auth;

use Illuminate\Contracts\Auth\Guard;
use Illuminate\Contracts\Auth\StatefulGuard;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;
use Lorisleiva\Actions\Concerns\AsAction;
use Nvade\Numerosis\Concerns\Auth\ForgetsGuardSession;
use Nvade\Numerosis\Enums\Tenancy\Context;

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
    use ForgetsGuardSession;

    public function handle(): void
    {
        $this->endTenantSession();
        $this->central()->logout();
        Session::invalidate();
    }

    /**
     * @see \Nvade\Numerosis\Http\Middleware\EnsureSessionMatchesTenant
     */
    private function endTenantSession(): void
    {
        $guard = $this->tenant();

        if (tenancy()->initialized) {
            $guard->logout();

            return;
        }

        $this->forgetGuardSession($guard);
    }

    public function tenant(): Guard|StatefulGuard
    {
        return Auth::guard(Context::Tenant->guard());
    }

    public function central(): Guard|StatefulGuard
    {
        return Auth::guard(Context::Central->guard());
    }
}
