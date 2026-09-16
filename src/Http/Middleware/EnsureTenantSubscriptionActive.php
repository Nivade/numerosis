<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Nvade\Numerosis\Contracts\Tenancy\Closable;
use Nvade\Numerosis\Contracts\Tenancy\Suspendable;
use Symfony\Component\HttpFoundation\Response;

/**
 * Refuses access to a suspended or closed tenant, so a tenant whose trial
 * ended and whose card failed cannot keep working indefinitely. Aliased as
 * `tenancy.subscription` and applied to the authenticated group in
 * `routes/tenant.php`. Never register it on the `tenant` group itself: it
 * redirects to `tenant.suspended`, which that group would re-gate into a loop.
 */
class EnsureTenantSubscriptionActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $currentTenant = tenant();

        // Ahead of suspension: a closure the owner asked for outranks a
        // payment that failed, and the two screens say opposite things.
        if ($currentTenant instanceof Closable && $currentTenant->isClosed()) {
            return to_route('tenant.closed');
        }

        if ($currentTenant instanceof Suspendable && $currentTenant->isSuspended()) {
            return to_route('tenant.suspended');
        }

        return $next($request);
    }
}
