<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Cache;

use Illuminate\Support\Facades\Config;
use Nvade\Numerosis\Enums\Tenancy\Context;
use Nvade\Numerosis\Models\Central\Tenant;

/**
 * Single source of truth for every cache key. Every reader and every
 * invalidator goes through here, since a key shape rebuilt by hand in a second
 * file is easy to get subtly wrong. Most are read through `global_cache()`,
 * which is not tenant-scoped; the ones documented as tenant-scoped go through
 * the `Cache` facade, which inside tenant context is stancl's prefixing manager.
 */
final class CacheKeys
{
    private function __construct() {}

    /**
     * The configurable prefix every key below is built on. Its own value is
     * never cached (each call reads config fresh), so changing it at runtime,
     * between tests for instance, takes effect immediately.
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

    public static function popularPaymentPlanSlug(): string
    {
        return self::prefix().':billing:popular_plan_slug';
    }

    /*
     * Keys owned by something other than core deliberately do not live here:
     * one registry per owner, so core carries no reference to a class it does
     * not ship.
     */
}
