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

    /** Global: the `tenants` schema is central and identical for every tenant. */
    public static function tenantCustomColumns(): string
    {
        return self::prefix().':tenant:custom_columns';
    }

    /** Global: holds the owner's `global_id`, never a user model. */
    public static function tenantOwnerGlobalId(string $tenantId): string
    {
        return self::prefix().":tenant:{$tenantId}:owner_global_id";
    }

    public static function availablePaymentPlans(): string
    {
        return self::prefix().':billing:available_payment_plans';
    }

    public static function popularPaymentPlanSlug(): string
    {
        return self::prefix().':billing:popular_plan_slug';
    }

    /** Global: the health document is central-wide and carries no tenant data. */
    public static function healthReport(): string
    {
        return self::prefix().':observability:health';
    }

    /** Global: written by the scheduler itself, read to answer whether it runs. */
    public static function schedulerHeartbeat(): string
    {
        return self::prefix().':observability:scheduler_heartbeat';
    }

    /** The gate that collapses one login lockout window into a single event. */
    public static function loginLockout(string $throttleKey): string
    {
        return self::prefix().':auth:login_lockout:'.sha1($throttleKey);
    }

    /**
     * Global: whether one hostname may be served, which the TLS ask endpoint
     * reads on every new SNI. Per domain rather than one big set, so a fleet's
     * worth of domains never rides in a single cache entry.
     */
    public static function servableDomain(string $domain): string
    {
        return self::prefix().':tls:servable:'.strtolower($domain);
    }

    /** Global: every servable domain, for the Traefik router document. */
    public static function servableDomains(): string
    {
        return self::prefix().':tls:servable_domains';
    }

    /** Global: one divergence alert per tenant, meter and period, however often reconciliation runs. */
    public static function usageDivergenceAlert(string $tenantId, string $eventName, string $periodStart): string
    {
        return self::prefix().":billing:usage_divergence:{$tenantId}:{$eventName}:{$periodStart}";
    }

    /** Global: the gate that collapses a burst of provisioning failures into one alert. */
    public static function provisioningAlertThrottle(): string
    {
        return self::prefix().':observability:provisioning_alert';
    }

    /*
     * Keys owned by something other than core deliberately do not live here:
     * one registry per owner, so core carries no reference to a class it does
     * not ship.
     */
}
