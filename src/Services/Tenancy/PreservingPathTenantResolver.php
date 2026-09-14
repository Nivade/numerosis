<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Services\Tenancy;

use Illuminate\Routing\Route;
use Override;
use Stancl\Tenancy\Contracts\Tenant;
use Stancl\Tenancy\Exceptions\TenantCouldNotBeIdentifiedByPathException;
use Stancl\Tenancy\Resolvers\PathTenantResolver;

/**
 * Keeps the `{tenant}` route parameter in place after identification.
 *
 * Stancl's own `PathTenantResolver` calls `Route::forgetParameter()` the moment
 * it resolves the tenant, so anything reading the parameter later in the same
 * request sees `hasParameter('tenant')` as false and silently takes its
 * no-tenant branch. Nothing here relies on stancl's removal of it.
 */
class PreservingPathTenantResolver extends PathTenantResolver
{
    /**
     * `stancl/tenancy` v3 calls `$route->forgetParameter()` inside
     * `resolveWithoutCache()` as well as in `resolved()`, so this override
     * replaces the body; delegating to the parent would forget the parameter
     * again.
     */
    #[Override]
    public function resolveWithoutCache(mixed ...$args): Tenant
    {
        /** @var Route $route */
        $route = $args[0];

        $id = $route->parameter(PathTenantResolver::$tenantParameterName);
        $id = is_string($id) || is_int($id) ? $id : null;

        if ($id !== null) {
            $tenant = tenancy()->find($id);

            if ($tenant !== null) {
                return $tenant;
            }
        }

        throw new TenantCouldNotBeIdentifiedByPathException((string) $id);
    }

    #[Override]
    public function resolved(Tenant $tenant, mixed ...$args): void
    {
        // Deliberately does not forgetParameter(); see the class docblock.
    }

    /**
     * Keys on the tenant id alone. The base implementation json-encodes
     * whatever `resolve()` was handed, which for this resolver is a `Route`:
     * every route shape produces a different key, while `getArgsForTenant()`
     * hands invalidation `[$tenant->id]`, so nothing cached was ever forgotten.
     */
    #[Override]
    public function getCacheKey(mixed ...$args): string
    {
        $id = $args[0] ?? null;

        if ($id instanceof Route) {
            $id = $id->parameter(PathTenantResolver::$tenantParameterName);
        }

        $id = is_string($id) || is_int($id) ? $id : null;

        return '_tenancy_resolver:'.static::class.':'.json_encode([$id]);
    }
}
