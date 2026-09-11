<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Http\Responses\Auth;

use Illuminate\Http\RedirectResponse;
use Laravel\Fortify\Contracts\LogoutResponse;
use Nvade\Numerosis\Routing\RouteNames;

class NumerosisLogoutResponse implements LogoutResponse
{
    public function toResponse($request): RedirectResponse
    {
        return redirect()->route(RouteNames::home());
    }
}
