<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Http\Middleware;

use Closure;
use Illuminate\Auth\AuthManager;
use Illuminate\Http\Request;
use Nvade\Numerosis\Enums\Tenancy\Context;
use Nvade\Numerosis\Models\Central\Tenant;
use Nvade\Numerosis\Models\User;
use Nvade\Numerosis\Routing\RouteNames;

/**
 * A removed member's tenant session survives the removal, since the guard
 * holds a per-database primary key and re-resolves nothing. This re-checks
 * membership per request, through the cached list `ForgetUserTenants`
 * invalidates on every membership write, and signs the guard out when it is
 * gone.
 */
class EnsureTenantMembership
{
    public function __construct(private readonly AuthManager $auth) {}

    public function handle(Request $request, Closure $next): mixed
    {
        $currentTenant = tenant();
        $guard = $this->auth->guard(Context::Tenant->guard());
        $user = $guard->user();

        if (! tenancy()->initialized || ! $currentTenant instanceof Tenant || ! $user instanceof User) {
            return $next($request);
        }

        if ($user->canAccessTenant($currentTenant)) {
            return $next($request);
        }

        $guard->logout();

        return redirect()->to(route(RouteNames::tenantsMine()))
            ->with('status', __('You no longer have access to that team.'));
    }
}
