<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Http\Middleware;

use Closure;
use Illuminate\Auth\AuthManager;
use Illuminate\Http\Request;
use Laravel\Fortify\Features as FortifyFeatures;
use Nvade\Numerosis\Actions\Queries\FindUserByGlobalId;
use Nvade\Numerosis\Enums\Tenancy\Context;
use Nvade\Numerosis\Models\Central\CentralUser;
use Nvade\Numerosis\Models\Central\Tenant;
use Nvade\Numerosis\Models\User;
use Nvade\Numerosis\Routing\RouteNames;

/**
 * Holds a tenant that requires a second factor to members who have confirmed
 * one. The factor lives on the central account, since that is the provider
 * every login checks credentials against, so enrolment is a central screen and
 * the redirect leaves the tenant domain.
 */
class EnsureTwoFactorEnrolled
{
    public function __construct(private readonly AuthManager $auth) {}

    public function handle(Request $request, Closure $next): mixed
    {
        $tenant = tenant();

        if (! FortifyFeatures::canManageTwoFactorAuthentication() || ! $tenant instanceof Tenant) {
            return $next($request);
        }

        if (! $tenant->requiresTwoFactorNow()) {
            return $next($request);
        }

        $user = $this->auth->guard(Context::Tenant->guard())->user();

        if (! $user instanceof User || $this->centralAccountOf($user)?->hasEnabledTwoFactorAuthentication() === true) {
            return $next($request);
        }

        return redirect()->to(route(RouteNames::twoFactorSettings()))
            ->with('status', __('This team requires two-factor authentication. Set it up to continue.'));
    }

    private function centralAccountOf(User $user): ?CentralUser
    {
        $central = FindUserByGlobalId::run($user->global_id, Context::Central);

        return $central instanceof CentralUser ? $central : null;
    }
}
