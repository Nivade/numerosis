<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/**
 * Stands in for `TenancyVersion::preventAccessFromCentralDomainsMiddleware()`
 * under `IdentificationMode::Path`, where tenant routes deliberately live on
 * the central domain (path-prefixed) rather than a distinct one — the
 * central-domain block would otherwise 404 every tenant-panel request. See
 * .claude/rules/identification-modes.md.
 */
class NullMiddleware
{
    public function handle(Request $request, Closure $next): mixed
    {
        return $next($request);
    }
}
