<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Listeners\Auth;

use Illuminate\Auth\Events\Logout;
use Illuminate\Contracts\Auth\StatefulGuard;
use Illuminate\Support\Facades\Auth;
use Nvade\Numerosis\Concerns\Auth\ForgetsGuardSession;
use Nvade\Numerosis\Enums\Tenancy\Context;

/**
 * Ends the session of whichever guard `POST /logout` did not log out itself.
 * Fortify's `AuthenticatedSessionController::destroy()` handles only
 * `config('fortify.guard')`, and that route is reachable on both central and
 * tenant domains. Each branch is guarded by `check()`/`tenancy()->initialized`,
 * because `logout()` re-dispatches `Logout` even when nothing was logged in.
 */
class EndOtherGuardSession
{
    use ForgetsGuardSession;

    public function handle(Logout $event): void
    {
        $central = Context::Central->guard();
        $tenant = Context::Tenant->guard();

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
        if (tenancy()->initialized) {
            $this->logoutGuardIfActive($guardName);

            return;
        }

        $this->forgetGuardSession(Auth::guard($guardName));
    }
}
