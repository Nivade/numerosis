<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Http\Middleware;

use Nvade\Numerosis\Models\Central\Tenant;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The access gate that did not exist before this — a tenant whose trial
 * ended and whose card failed used to keep working forever, on every plan,
 * not just async payment methods. See custom-checkout.md, "The access gate
 * that does not exist".
 *
 * Registered on the tenant Filament panel, after tenancy identification and
 * auth so `tenant()` and the guard are both resolved. A suspended tenant is
 * bounced to the ⚡suspended page — a plain route outside the panel, so this
 * middleware (scoped to the panel) never runs against it and can't loop.
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
