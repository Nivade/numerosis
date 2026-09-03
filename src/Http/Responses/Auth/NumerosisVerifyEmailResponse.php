<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Http\Responses\Auth;

use Illuminate\Http\RedirectResponse;
use Laravel\Fortify\Contracts\VerifyEmailResponse;
use Nvade\Numerosis\Support\Routes\RouteNames;

/**
 * Bound against Fortify's `VerifyEmailResponse` contract in
 * `NumerosisServiceProvider::packageRegistered()`. Port of the old
 * `Http\Controllers\Auth\VerifyEmailController`'s redirect, which landed on
 * the tenant workspace list rather than Fortify's default `fortify.home`.
 */
class NumerosisVerifyEmailResponse implements VerifyEmailResponse
{
    public function toResponse($request): RedirectResponse
    {
        return redirect()->intended(route(RouteNames::tenantsMine(), absolute: false).'?verified=1');
    }
}
