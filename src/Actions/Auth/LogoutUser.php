<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Actions\Auth;

use Illuminate\Contracts\Auth\Guard;
use Illuminate\Contracts\Auth\StatefulGuard;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Config;
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
        $this->tenant()->logout();
        $this->central()->logout();
        Session::invalidate();
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
