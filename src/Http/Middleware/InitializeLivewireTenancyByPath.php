<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Stancl\Tenancy\Tenancy;

/**
 * Identifies the tenant for `/livewire/update` under `IdentificationMode::Path`.
 * That route is global and carries no `{tenant}` parameter, which
 * `Stancl\Tenancy\Middleware\InitializeTenancyByPath` requires, so the tenant
 * is read off the `Referer` header's first path segment. A missing `Referer`
 * leaves the request in an un-initialized central context and does not throw.
 */
class InitializeLivewireTenancyByPath
{
    public function __construct(private readonly Tenancy $tenancy) {}

    public function handle(Request $request, Closure $next): mixed
    {
        $id = $this->tenantIdFromReferer($request);

        if ($id !== null) {
            $tenant = tenancy()->find($id);

            if ($tenant !== null) {
                $this->tenancy->initialize($tenant);
            }
        }

        return $next($request);
    }

    private function tenantIdFromReferer(Request $request): ?string
    {
        $referer = $request->headers->get('referer');

        if (! is_string($referer) || $referer === '') {
            return null;
        }

        $path = trim((string) parse_url($referer, PHP_URL_PATH), '/');

        if ($path === '') {
            return null;
        }

        $segment = explode('/', $path)[0];

        return $segment === '' ? null : $segment;
    }
}
