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
 * Stancl's own `PathTenantResolver` removes it the moment it resolves the
 * tenant (`Route::forgetParameter()`), so anything reading it later in the
 * same request — a controller signature, `route()` regeneration of the
 * current URL, any middleware ordered after tenancy identification — sees
 * `$request->route()->hasParameter('tenant')` as false and silently takes
 * its no-tenant branch rather than 404ing. Nothing in this package's
 * path-mode support relies on stancl's removal of it. See
 * `.ai/rules/identification-modes.md`.
 */
class PreservingPathTenantResolver extends PathTenantResolver
{
    /**
     * v3 calls `$route->forgetParameter()` inside `resolveWithoutCache()` as
     * well as in `resolved()`, so the body has to be replaced rather than
     * delegated to.
     *
     * **dev-master only forgets the parameter in `resolved()`** — which this
     * class already overrides — so on that version this method should
     * delegate to the parent instead, keeping its binding-field resolution
     * and `allowedExtraModelColumns()` check. It also replaces the static
     * `$tenantParameterName` property read below with a static method; see
     * `.ai/rules/stancl-tenancy-v4.md`.
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
