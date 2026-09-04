<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Resolvers;

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
        /** @var \Illuminate\Routing\Route $route */
        $route = $args[0];

        $id = $route->parameter(PathTenantResolver::$tenantParameterName);
        $id = is_string($id) || is_int($id) ? $id : null;

        if ($id !== null && $tenant = tenancy()->find($id)) {
            return $tenant;
        }

        throw new TenantCouldNotBeIdentifiedByPathException((string) $id);
    }

    #[Override]
    public function resolved(Tenant $tenant, mixed ...$args): void
    {
        // Deliberately does not forgetParameter() — see class docblock.
    }
}
