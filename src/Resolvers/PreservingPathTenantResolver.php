<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Resolvers;

use Nvade\Numerosis\Support\Tenancy\TenancyVersion;
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
    /**
     * The two versions forget the parameter in different places, so only one
     * of them needs this method reimplemented at all:
     *
     * - **v3** calls `$route->forgetParameter()` inside its own
     *   `resolveWithoutCache()` as well as in `resolved()`, so the body has to
     *   be replaced.
     * - **dev-master** only does it in `resolved()` — which this class already
     *   overrides — so the parent body is safe to delegate to, and delegating
     *   keeps its binding-field resolution and `allowedExtraModelColumns()`
     *   whitelist check, both of which a reimplementation here would silently
     *   drop.
     */
    #[Override]
    public function resolveWithoutCache(mixed ...$args): Tenant
    {
        if (TenancyVersion::isDevMaster()) {
            return parent::resolveWithoutCache(...$args);
        }

        /** @var \Illuminate\Routing\Route $route */
        $route = $args[0];

        $id = $route->parameter(TenancyVersion::pathTenantParameterName());
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
