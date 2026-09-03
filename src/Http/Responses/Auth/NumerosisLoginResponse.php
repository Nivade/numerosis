<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Http\Responses\Auth;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Uri;
use Laravel\Fortify\Contracts\LoginResponse;
use Nvade\Numerosis\Support\Routes\RouteNames;

/**
 * Bound against Fortify's `LoginResponse` contract in
 * `NumerosisServiceProvider::packageRegistered()`. Port of the old
 * `ResolvePostLoginRedirectUrl` action, now receiving the request directly —
 * which drops that action's own `request()` helper call.
 */
class NumerosisLoginResponse implements LoginResponse
{
    public function toResponse($request): RedirectResponse
    {
        return redirect()->to($this->url($request));
    }

    private function url(Request $request): string
    {
        $default = tenancy()->initialized ? '/' : route(RouteNames::tenantsMine());

        return $this->intendedUrlForCurrentHost($request) ?? $default;
    }

    /**
     * `url.intended` lives in a session shared across every tenant subdomain
     * and the central domain alike, so a value stashed while redirecting
     * from an unrelated host is still there when this runs. Only trust it
     * when it actually points at the host being logged into; otherwise it
     * silently bounces the user to whatever domain last stored one.
     */
    private function intendedUrlForCurrentHost(Request $request): ?string
    {
        $intended = Session::get('url.intended');

        if (! is_string($intended)) {
            return null;
        }

        return Uri::of($intended)->host() === $request->getHost() ? $intended : null;
    }
}
