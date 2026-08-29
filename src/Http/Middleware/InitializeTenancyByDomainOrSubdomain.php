<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Http\Middleware;

use Closure;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Nvade\Numerosis\Support\Tenancy\TenancyConfigKeys;
use Override;

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
        $centralDomains = $this->repository->get(TenancyConfigKeys::key('central_domains'), []);

        return new Collection($centralDomains)->contains($request->getHost());
    }
}
