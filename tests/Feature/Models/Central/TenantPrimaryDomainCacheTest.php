<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Tests\Feature\Models\Central;

use App\Models\Central\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Nvade\Numerosis\Cache\GlobalCache;
use Nvade\Numerosis\Tests\Concerns\PinsGlobalCache;
use Nvade\Numerosis\Tests\Concerns\UsesSerializingGlobalCache;
use Nvade\Numerosis\Tests\TestCase;

class TenantPrimaryDomainCacheTest extends TestCase
{
    use PinsGlobalCache;
    use RefreshDatabase;
    use UsesSerializingGlobalCache;

    /**
     * primaryDomain() is cached and read by outbound URLs (verification
     * emails, the Socialite redirect, the tenant switcher). A newer domain,
     * or the removal of the cached one, must be reflected rather than
     * silently keeping the first answer forever.
     */
    public function test_adding_a_newer_domain_invalidates_the_cached_primary_domain(): void
    {
        $this->pinGlobalCache();

        $tenant = Tenant::create(['id' => 'primary-domain-'.uniqid()]);
        $original = $tenant->domains()->create([
            'id' => $tenant->id.'-original',
            'domain' => $this->tenantDomain($tenant->id.'-original'),
        ]);

        $this->assertSame($original->id, $tenant->primaryDomain()?->id);

        // primaryDomain() orders on `created_at`, which is second-precision,
        // and nothing breaks the tie: two domains added in the same second
        // come back in whatever order the driver happens to return.
        $this->travel(1)->second();

        $newer = $tenant->domains()->create([
            'id' => $tenant->id.'-newer',
            'domain' => $this->tenantDomain($tenant->id.'-newer'),
        ]);

        $this->assertSame($newer->id, $tenant->primaryDomain()?->id);
    }

    public function test_deleting_the_cached_primary_domain_invalidates_it(): void
    {
        $this->pinGlobalCache();

        $tenant = Tenant::create(['id' => 'primary-domain-del-'.uniqid()]);
        $domain = $tenant->domains()->create([
            'id' => $tenant->id.'-only',
            'domain' => $this->tenantDomain($tenant->id.'-only'),
        ]);

        $this->assertSame($domain->id, $tenant->primaryDomain()?->id);

        $domain->delete();

        $this->assertNull($tenant->primaryDomain());
    }

    /**
     * A host hardened with `cache.serializable_classes` cannot round-trip a
     * cached model: the read returns `__PHP_Incomplete_Class` with no
     * exception and no log line. Attributes survive it.
     */
    public function test_the_cached_value_survives_a_serializable_classes_allowlist(): void
    {
        $this->useSerializingStore();

        $tenant = Tenant::create(['id' => 'primary-domain-serial-'.uniqid()]);
        $domain = $tenant->domains()->create([
            'id' => $tenant->id.'-only',
            'domain' => $this->tenantDomain($tenant->id.'-only'),
        ]);

        $this->assertSame($domain->id, $tenant->primaryDomain()?->id);

        // Second read comes off the cache, which is where a stored model breaks.
        $this->assertSame($domain->id, $tenant->primaryDomain()?->id);
    }

    /** Proves the store above really does mangle objects, so the test above is not vacuous. */
    public function test_the_probe_store_mangles_a_cached_model(): void
    {
        $this->useSerializingStore();

        GlobalCache::store()->put('probe', new Tenant, 60);

        $this->assertInstanceOf('__PHP_Incomplete_Class', GlobalCache::store()->get('probe'));
    }

    /** "No domain" is cached too; in path mode no tenant has a domain row at all. */
    public function test_a_tenant_without_a_domain_is_only_queried_once(): void
    {
        $this->pinGlobalCache();

        $tenant = Tenant::create(['id' => 'primary-domain-none-'.uniqid()]);

        $this->assertNull($tenant->primaryDomain());

        $connection = $this->centralDatabase();
        $connection->flushQueryLog();
        $connection->enableQueryLog();

        $this->assertNull($tenant->primaryDomain());

        $this->assertEmpty(
            $connection->getQueryLog(),
            'A tenant with no domain row re-queried on every call.'
        );
    }
}
