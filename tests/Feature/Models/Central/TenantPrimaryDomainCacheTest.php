<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Tests\Feature\Models\Central;

use Nvade\Numerosis\Models\Central\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Nvade\Numerosis\Tests\Concerns\PinsGlobalCache;
use Nvade\Numerosis\Tests\TestCase;

class TenantPrimaryDomainCacheTest extends TestCase
{
    use PinsGlobalCache;
    use RefreshDatabase;

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
}
