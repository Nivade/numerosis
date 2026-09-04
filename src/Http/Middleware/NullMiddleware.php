<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/**
 * Stands in for `Stancl\Tenancy\Middleware\PreventAccessFromCentralDomains`
 * under `IdentificationMode::Path`, where tenant routes deliberately live on
 * the central domain (path-prefixed) instead of a distinct one. The
 * central-domain block would otherwise 404 every tenant-panel request.
 */
class NullMiddleware
{
    public function handle(Request $request, Closure $next): mixed
    {
        return $next($request);
    }
}
