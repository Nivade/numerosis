<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Boot;

use Illuminate\Support\Facades\Config;
use Nvade\Numerosis\Enums\Tenancy\IdentificationMode;
use Nvade\Numerosis\Http\Middleware\InitializeLivewireTenancyByPath;
use Nvade\Numerosis\Http\Middleware\InitializeTenancyByDomainOrSubdomain;
use Nvade\Numerosis\Http\Middleware\NullMiddleware;
use Nvade\Numerosis\Models\Central\Tenant;
use Nvade\Numerosis\Numerosis;
use Stancl\Tenancy\Middleware\InitializeTenancyByDomain;
use Stancl\Tenancy\Middleware\InitializeTenancyByPath;
use Stancl\Tenancy\Middleware\PreventAccessFromCentralDomains;

/**
 * Config-derived answers about how this host identifies and caches tenants —
 * read by {@see \Nvade\Numerosis\Http\Middleware\InitializeTenancy} and
 * {@see \Nvade\Numerosis\Http\Middleware\TenantRouteGuard} at request time.
 */
final class TenancyRouting
{
    /**
     * The middleware that identifies a tenant from the request, chosen by
     * {@see IdentificationMode::current()}.
     */
    public static function identificationMiddleware(): string
    {
        return match (IdentificationMode::current()) {
            IdentificationMode::Subdomain => InitializeTenancyByDomainOrSubdomain::class,
            IdentificationMode::CustomDomain => InitializeTenancyByDomain::class,
            IdentificationMode::Path => InitializeTenancyByPath::class,
        };
    }

    /**
     * The Livewire update route carries no `{tenant}` parameter, so
     * `InitializeTenancyByPath` cannot be applied to it. Every other mode
     * identifies by domain, which needs no route parameter.
     *
     * @see InitializeLivewireTenancyByPath
     */
    public static function livewireUpdateIdentificationMiddleware(): string
    {
        return IdentificationMode::current() === IdentificationMode::Path
            ? InitializeLivewireTenancyByPath::class
            : self::identificationMiddleware();
    }

    /**
     * The central-domain-block gate used inside the `tenant` middleware
     * group. Under `IdentificationMode::Path`, tenant routes deliberately
     * live on the central domain (path-prefixed), so the ordinary block
     * would 404 every tenant request.
     */
    public static function tenancyRouteMiddleware(): string
    {
        return IdentificationMode::current() === IdentificationMode::Path
            ? NullMiddleware::class
            : PreventAccessFromCentralDomains::class;
    }

    /**
     * `DomainTenantResolver` caches a whole tenant model, so the cache follows
     * what `cache.serializable_classes` can store: an allowlist has to name the
     * tenant model, `false` disables the cache, and
     * `numerosis.tenancy.cache_resolved_tenants` overrides either way. A store
     * that cannot unserialize it returns `__PHP_Incomplete_Class` silently.
     */
    public static function shouldCacheResolvedTenants(): bool
    {
        $configured = Config::get('numerosis.tenancy.cache_resolved_tenants');

        if (is_bool($configured)) {
            return $configured;
        }

        $serializableClasses = Config::get('cache.serializable_classes');

        // null is Laravel's "no restriction" value: the stores only pass
        // `allowed_classes` to unserialize() when this is non-null.
        if ($serializableClasses === null || $serializableClasses === true) {
            return true;
        }

        if (! is_array($serializableClasses)) {
            return false;
        }

        $tenantModel = Config::get('tenancy.tenant_model') ?? Numerosis::model(Tenant::class);

        return in_array($tenantModel, $serializableClasses, true);
    }
}
