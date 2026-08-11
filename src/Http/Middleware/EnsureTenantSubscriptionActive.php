<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Nvade\Numerosis\Models\Central\Tenant;
use Symfony\Component\HttpFoundation\Response;

/**
 * Refuses panel access to a suspended tenant — the gate that stops a tenant
 * whose trial ended and whose card failed from working indefinitely.
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
