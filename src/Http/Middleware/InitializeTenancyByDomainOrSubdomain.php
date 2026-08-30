<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Http\Middleware;

use Closure;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Nvade\Numerosis\Support\Tenancy\TenancyConfigKeys;
use Nvade\Numerosis\Support\Tenancy\TenancyVersion;
use Override;
use Stancl\Tenancy\Resolvers\DomainTenantResolver;
use Stancl\Tenancy\Tenancy;

/**
 * The parent class's shape is not the same between versions: on dev-master
 * it `extends InitializeTenancyByDomain`, whose constructor promotes
 * `Tenancy $tenancy` / `DomainTenantResolver $resolver` — declaring our own
 * constructor without forwarding to `parent::__construct()` left those
 * typed properties uninitialized, throwing the moment `parent::handle()`
 * touched `$this->tenancy`. On v3, `InitializeTenancyByDomainOrSubdomain`
 * is a standalone dispatcher with no `__construct` at all (it resolves
 * `InitializeTenancyByDomain`/`InitializeTenancyBySubdomain` from the
 * container inside `handle()` instead), so calling `parent::__construct()`
 * there is itself a fatal ("Cannot call constructor") — there is nothing to
 * call. See `.claude/rules/stancl-tenancy-v4.md`.
 */
class InitializeTenancyByDomainOrSubdomain extends \Stancl\Tenancy\Middleware\InitializeTenancyByDomainOrSubdomain
{
    public function __construct(
        Tenancy $tenancy,
        DomainTenantResolver $resolver,
        private readonly Repository $repository,
    ) {
        if (TenancyVersion::isDevMaster()) {
            parent::__construct($tenancy, $resolver);
        }
    }

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
        $centralDomains = $this->repository->get(TenancyConfigKeys::key('central_domains'), []);

        return new Collection($centralDomains)->contains($request->getHost());
    }
}
