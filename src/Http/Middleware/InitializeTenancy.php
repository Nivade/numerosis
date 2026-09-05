<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Nvade\Numerosis\Providers\TenancyServiceProvider;

/**
 * Aliased to `tenancy.identification` so the alias itself is a stable class
 * literal, resolvable before `RegisterFacades` runs. The real identification
 * middleware is picked from `IdentificationMode::current()` here, at
 * request/`handle()` time, when config is guaranteed to be loaded — not when
 * the alias map is built.
 */
class InitializeTenancy
{
    public function handle(Request $request, Closure $next): mixed
    {
        return app(TenancyServiceProvider::identificationMiddleware())->handle($request, $next);
    }
}
