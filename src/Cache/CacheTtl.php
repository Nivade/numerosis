<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Cache;

use Illuminate\Support\Facades\Config;

/**
 * The configured lifetime of every key in {@see CacheKeys}, in seconds. A null
 * means "do not cache": {@see GlobalCache::remember()} skips the store
 * entirely instead of writing a zero-second entry.
 */
final class CacheTtl
{
    /** How much longer than its fresh window a flexible key may be served stale. */
    private const int STALE_MULTIPLIER = 4;

    private function __construct() {}

    public static function userTenants(): ?int
    {
        return self::seconds('user_tenants', 3600);
    }

    public static function userModel(): ?int
    {
        return self::seconds('user_model', 3600);
    }

    public static function tenantPrimaryDomain(): ?int
    {
        return self::seconds('tenant_primary_domain', 3600);
    }

    public static function tenantCustomColumns(): ?int
    {
        return self::seconds('tenant_custom_columns', 86400);
    }

    public static function tenantOwnerGlobalId(): ?int
    {
        return self::seconds('tenant_owner_global_id', 3600);
    }

    public static function availablePaymentPlans(): ?int
    {
        return self::seconds('available_payment_plans', 3600);
    }

    public static function popularPaymentPlanSlug(): ?int
    {
        return self::seconds('popular_payment_plan_slug', 300);
    }

    public static function healthReport(): ?int
    {
        return self::seconds('health_report', 5);
    }

    public static function entitlements(): ?int
    {
        return self::seconds('entitlements', 300);
    }

    /**
     * The `[fresh, stale]` pair {@see GlobalCache::flexible()} takes.
     *
     * @return array{int, int}|null
     */
    public static function window(?int $fresh): ?array
    {
        if ($fresh === null) {
            return null;
        }

        return [$fresh, $fresh * self::STALE_MULTIPLIER];
    }

    private static function seconds(string $key, int $default): ?int
    {
        $configured = Config::get("numerosis.cache.ttl.{$key}", $default);

        return is_numeric($configured) ? (int) $configured : null;
    }
}
