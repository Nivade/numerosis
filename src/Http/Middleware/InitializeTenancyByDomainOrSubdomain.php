<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Http\Middleware;

use Closure;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Override;

/**
 * Lets a central domain through untouched instead of trying to identify a
 * tenant from it.
 *
 * The parent declares no constructor on v3 — it is a standalone dispatcher
 * that resolves `InitializeTenancyByDomain`/`InitializeTenancyBySubdomain`
 * from the container inside `handle()`. **On dev-master it instead
 * `extends InitializeTenancyByDomain`, whose promoted `Tenancy`/
 * `DomainTenantResolver` properties a subclass constructor must forward to**
 * or `parent::handle()` throws on an uninitialized typed property; see
 * `.claude/rules/stancl-tenancy-v4.md` when porting.
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
