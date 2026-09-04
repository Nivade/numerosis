<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Http\Middleware;

use Closure;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Override;

/**
 * Lets a central domain through untouched, identifying no tenant from it. On
 * `stancl/tenancy` v3 the parent declares no constructor: it is a standalone
 * dispatcher that resolves
 * `InitializeTenancyByDomain`/`InitializeTenancyBySubdomain` from the
 * container inside `handle()`.
 */
class InitializeTenancyByDomainOrSubdomain extends \Stancl\Tenancy\Middleware\InitializeTenancyByDomainOrSubdomain
{
    public function __construct(private readonly Repository $repository) {}

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
        /** @var array<int, string> $centralDomains */
        $centralDomains = $this->repository->get('tenancy.central_domains', []);

        return new Collection($centralDomains)->contains($request->getHost());
    }
}
