<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Resolvers;

use Override;
use Stancl\Tenancy\Contracts\Tenant;
use Stancl\Tenancy\Exceptions\TenantCouldNotBeIdentifiedByPathException;
use Stancl\Tenancy\Resolvers\PathTenantResolver;

/**
 * Filament's own tenant identification (`Filament\Http\Middleware\IdentifyTenant`)
 * reads the `{tenant}` route parameter later in the same request, after our
 * tenancy-identification middleware has already resolved it. Stancl's own
 * `PathTenantResolver` removes that parameter the moment it resolves the
 * tenant (`Route::forgetParameter()`), so by the time Filament's middleware
 * runs, `$request->route()->hasParameter('tenant')` is false and
 * `Filament::setTenant()` is silently never called — the request proceeds
 * with tenancy initialized but no Filament tenant, which is not caught by
 * `Filament\Models\Contracts\HasTenants` checks the same way a 404 would be.
 * This subclass keeps the parameter in place; nothing else in this package's
 * path-mode support relies on stancl's own removal of it. See
 * .claude/rules/identification-modes.md.
 */
class PreservingPathTenantResolver extends PathTenantResolver
{
    #[Override]
    public function resolveWithoutCache(mixed ...$args): Tenant
    {
        /** @var \Illuminate\Routing\Route $route */
        $route = $args[0];

        if ($id = $route->parameter(static::$tenantParameterName)) {
            if ($tenant = tenancy()->find($id)) {
                return $tenant;
            }
        }

        throw new TenantCouldNotBeIdentifiedByPathException($id ?? null);
    }

    #[Override]
    public function resolved(Tenant $tenant, mixed ...$args): void
    {
        // Deliberately does not forgetParameter() — see class docblock.
    }
}
