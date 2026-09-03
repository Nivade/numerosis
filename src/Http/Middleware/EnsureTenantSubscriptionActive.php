<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Nvade\Numerosis\Models\Central\Tenant;
use Symfony\Component\HttpFoundation\Response;

/**
 * Refuses access to a suspended tenant — the gate that stops a tenant whose
 * trial ended and whose card failed from working indefinitely.
 *
 * Aliased as `tenancy.subscription` and applied to the nested authenticated
 * group in `routes/tenant.php`, after tenancy identification and auth so
 * `tenant()` is resolved. **Never register it on the `tenant` middleware
 * group itself**: it redirects to `tenant.suspended`, which is a tenant route
 * too, so a group-wide registration re-gates its own redirect target into an
 * infinite loop.
 *
 * It reads the tenant, not the user, so auth is not a precondition — the
 * authenticated group is where it sits because that is the surface worth
 * gating, not because it needs a guard.
 */
class EnsureTenantSubscriptionActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $currentTenant = tenant();

        if ($currentTenant instanceof Tenant && $currentTenant->isSuspended()) {
            return to_route('tenant.suspended');
        }

        return $next($request);
    }
}
