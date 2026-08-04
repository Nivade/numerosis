<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Support\Cache;

use Nvade\Numerosis\Enums\Tenancy\Context;
use Nvade\Numerosis\Models\Central\Tenant;
use Illuminate\Support\Facades\Config;

/**
 * Single source of truth for every cache key used by the app. Every reader and
 * every invalidator must go through here — a key shape (e.g. the tenant suffix
 * on a tenant-context user model) is easy to redrive by hand in a second file
 * and get subtly wrong.
 *
 * Most of these are read through `global_cache()`, which is not tenant-scoped;
 * the ones documented as tenant-scoped go through the `Cache` facade, which
 * inside tenant context is Stancl's tenant-prefixing manager.
 */
final class CacheKeys
{
    private function __construct() {}

    /**
     * The configurable prefix every key below is built on. Its own value is
     * never cached (each call reads config fresh), so changing it at runtime
     * — e.g. between tests — takes effect immediately.
     */
    private static function prefix(): string
    {
        return Config::string('numerosis.cache.prefix', 'numerosis');
    }

    public static function userTenants(string $globalId): string
    {
        return self::prefix().":user:{$globalId}:tenants";
    }

    /**
     * The tenant suffix is what keeps one tenant's cached user row out of
     * another's: `global_cache()` carries no tenant prefix of its own, and
     * tenant user ids are per-database integers.
     */
    public static function userModel(string $globalId, Context $context): string
    {
        if ($context === Context::Central) {
            return self::prefix().":user:{$globalId}:central_model";
        }

        $currentTenant = tenancy()->initialized ? tenant() : null;
        $tenantKey = $currentTenant instanceof Tenant ? $currentTenant->getTenantKey() : 'none';

        return self::prefix().":user:{$globalId}:tenant_model:{$tenantKey}";
    }

    public static function tenantPrimaryDomain(string $tenantId): string
    {
        return self::prefix().":tenant:{$tenantId}:primary_domain";
    }

    public static function availablePaymentPlans(): string
    {
        return self::prefix().':billing:available_payment_plans';
    }

    public static function popularPaymentPlanId(): string
    {
        return self::prefix().':billing:popular_plan_id';
    }

    public static function availableModules(): string
    {
        return self::prefix().':billing:available_modules';
    }

    /**
     * Tenant-scoped throttle marker, not a value cache — see
     * {@see \Nvade\Numerosis\Http\Middleware\UpdateUserLastSeenMiddleware}. The guard is
     * part of the key because tenant and central user ids are unrelated
     * sequences that would otherwise collide on small integers.
     */
    public static function lastSeenThrottle(string $guard, int|string $userId): string
    {
        return self::prefix().":last-seen:{$guard}:{$userId}";
    }

    /*
     * Module-owned keys deliberately do not live here. The branding module
     * keeps its own (Nvade\Branding\Support\BrandingCache) so core carries no
     * reference to an optional app-modules package — the same discipline, one
     * registry per owner.
     */
}
