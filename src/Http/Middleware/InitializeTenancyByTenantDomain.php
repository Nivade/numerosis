<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Nvade\Numerosis\Numerosis;
use Override;
use Stancl\Tenancy\Middleware\InitializeTenancyByDomain;

/**
 * Not stancl's `InitializeTenancyByDomainOrSubdomain`: that one switches to
 * the label-only subdomain resolver whenever the host ends with a central
 * domain, which is every host served at the apex, while `CreateTenantDomain`
 * always writes the fully-qualified name.
 */
class InitializeTenancyByTenantDomain extends InitializeTenancyByDomain
{
    #[Override]
    public function handle($request, Closure $next): mixed
    {
        if ($this->shouldSkip($request)) {
            return $next($request);
        }

        return parent::handle($request, $next);
    }

    public function shouldSkip(Request $request): bool
    {
        return Numerosis::isCentralDomain($request);
    }
}
