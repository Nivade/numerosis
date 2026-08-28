<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Actions\Auth;

use Illuminate\Auth\SessionGuard;
use Illuminate\Contracts\Auth\Guard;
use Illuminate\Contracts\Auth\StatefulGuard;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\Session;
use Lorisleiva\Actions\Concerns\AsAction;
use Lorisleiva\Actions\Concerns\AsController;
use Nvade\Numerosis\Support\Routes\RouteNames;

class LogoutUser
{
    use AsAction;
    use AsController;

    public function handle(): void
    {
        $this->endTenantSession();
        $this->central()->logout();
        Session::invalidate();
    }

    /**
     * Ends the tenant guard's session without ever resolving a user outside
     * tenant context.
     *
     * `POST /logout` is a central-domain route as well as a tenant one, and
     * `SessionGuard::logout()` resolves the current user before clearing
     * anything. The tenant provider's model has no connection of its own, so
     * with tenancy uninitialized that lookup runs against the *central*
     * database: it hydrates whichever central user happens to hold the id
     * the shared session carries — soft-deleted rows included, since the
     * tenant user model does not soft-delete — and then cycles a remember
     * token onto that row. The write lands on a stranger's central record,
     * and the resulting `SyncedResourceSaved` carries no tenant, so the
     * queued sync listener dies with `ModelNotSyncMasterException` twenty
     * times over. Dropping the guard's state directly is what
     * {@see \Nvade\Numerosis\Http\Middleware\EnsureSessionMatchesTenant}
     * already does for the same reason.
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

    public function asController(): RedirectResponse
    {
        $this->handle();

        return to_route(RouteNames::home());
    }

    public function htmlResponse(): RedirectResponse
    {
        return to_route(RouteNames::home());
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
