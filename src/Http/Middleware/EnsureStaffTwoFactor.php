<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Http\Middleware;

use Closure;
use Illuminate\Auth\AuthManager;
use Illuminate\Http\Request;
use Laravel\Fortify\Features as FortifyFeatures;
use Nvade\Numerosis\Enums\Tenancy\Context;
use Nvade\Numerosis\Models\Central\CentralUser;

/**
 * No grace and no toggle. The staff screens suspend tenants and mint
 * impersonation links, so the credential behind them is the highest-value one
 * in the installation.
 */
class EnsureStaffTwoFactor
{
    public function __construct(private readonly AuthManager $auth) {}

    public function handle(Request $request, Closure $next): mixed
    {
        if (! FortifyFeatures::canManageTwoFactorAuthentication()) {
            return $next($request);
        }

        $user = $this->auth->guard(Context::Central->guard())->user();

        if ($user instanceof CentralUser && $user->hasEnabledTwoFactorAuthentication()) {
            return $next($request);
        }

        return redirect()->to(route('settings.two-factor'))
            ->with('status', __('Staff screens require two-factor authentication.'));
    }
}
