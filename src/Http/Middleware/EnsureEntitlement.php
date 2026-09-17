<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Nvade\Numerosis\Contracts\Billing\Entitlements;

/**
 * UX, not enforcement: a route gate keeps a screen out of reach, and the
 * action behind it still has to check for itself. Anything reachable by a
 * queue job, a webhook or an API token never passes through here.
 */
class EnsureEntitlement
{
    public function __construct(private readonly Entitlements $entitlements) {}

    public function handle(Request $request, Closure $next, string $capability): mixed
    {
        $this->entitlements->assertAllowed($capability);

        return $next($request);
    }
}
