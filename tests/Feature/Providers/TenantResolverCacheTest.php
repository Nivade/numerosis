<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Tests\Feature\Providers;

use App\Models\Central\Tenant;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Nvade\Numerosis\Providers\TenancyServiceProvider;
use Nvade\Numerosis\Tests\TestCase;

/**
 * `DomainTenantResolver::$shouldCache` used to be set to `true`
 * unconditionally, which is only safe on a host whose cache config allows
 * `unserialize()` to rebuild an object. A fresh Laravel app ships
 * `cache.serializable_classes => false` — hardening, and the only tenancy
 * consequence is that the *second* request for a tenant domain fails, never
 * the first, with `Argument #1 ($tenant) must be of type Tenant,
 * __PHP_Incomplete_Class given`. Nothing in the reading path logs or throws
 * at the point the value is mangled.
 */
class TenantResolverCacheTest extends TestCase
{
    public function test_it_caches_when_the_cache_config_places_no_restriction(): void
    {
        Config::set('numerosis.tenancy.cache_resolved_tenants');
        Config::set('cache.serializable_classes');

        $this->assertTrue(TenancyServiceProvider::shouldCacheResolvedTenants());
    }

    public function test_it_does_not_cache_when_the_store_cannot_unserialize_any_object(): void
    {
        Config::set('numerosis.tenancy.cache_resolved_tenants');
        Config::set('cache.serializable_classes', false);

        $this->assertFalse(TenancyServiceProvider::shouldCacheResolvedTenants());
    }

    public function test_an_allowlist_has_to_name_the_configured_tenant_model(): void
    {
        Config::set('numerosis.tenancy.cache_resolved_tenants');
        Config::set('cache.serializable_classes', ['DateTimeImmutable']);

        $this->assertFalse(TenancyServiceProvider::shouldCacheResolvedTenants());

        Config::set('cache.serializable_classes', ['DateTimeImmutable', Config::string('tenancy.tenant_model')]);

        $this->assertTrue(TenancyServiceProvider::shouldCacheResolvedTenants());
    }

    public function test_a_host_can_decide_it_either_way(): void
    {
        Config::set('cache.serializable_classes', false);
        Config::set('numerosis.tenancy.cache_resolved_tenants', true);

        $this->assertTrue(TenancyServiceProvider::shouldCacheResolvedTenants());

        Config::set('cache.serializable_classes');
        Config::set('numerosis.tenancy.cache_resolved_tenants', false);

        $this->assertFalse(TenancyServiceProvider::shouldCacheResolvedTenants());
    }

    /**
     * The mechanism itself, so the reasoning above is not taken on trust: this
     * is a plain `DateTimeImmutable` — nothing to do with tenancy — round
     * tripped through the cache under a host's stock `false`. It comes back as
     * `__PHP_Incomplete_Class`, from a `Cache::get()` that reports success.
     */
    public function test_a_stock_serializable_classes_false_silently_mangles_any_cached_object(): void
    {
        Config::set('cache.serializable_classes', false);
        Config::set('cache.stores.probe', ['driver' => 'array', 'serialize' => true]);

        $store = Cache::store('probe');
        $store->put('probe', new Tenant, 60);

        $this->assertInstanceOf(
            '__PHP_Incomplete_Class',
            $store->get('probe'),
            'A cached object survived config(cache.serializable_classes) === false — if Laravel has changed this, shouldCacheResolvedTenants() can stop reading that key.',
        );
    }
}
