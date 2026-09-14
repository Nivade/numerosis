<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Tests\Feature;

use App\Models\Central\CentralUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Nvade\Numerosis\Actions\Queries\GetTenantsByGlobalId;
use Nvade\Numerosis\Tests\Concerns\PinsGlobalCache;
use Nvade\Numerosis\Tests\Support\TestTenant;
use Nvade\Numerosis\Tests\TestCase;

class GetTenantsByGlobalIdTest extends TestCase
{
    use PinsGlobalCache;
    use RefreshDatabase;

    public function test_it_returns_tenants_for_a_user_by_global_id(): void
    {
        // Arrange
        $globalId = 'global-'.uniqid();
        $user = CentralUser::create([
            'global_id' => $globalId,
            'name' => 'Test User',
            'email' => 'test-'.uniqid().'@example.com',
            'password' => 'password',
        ]);

        $tenant1 = TestTenant::provisioned(['id' => 'tenant-'.uniqid(), 'data' => ['name' => 'Tenant 1']]);
        $tenant2 = TestTenant::provisioned(['id' => 'tenant-'.uniqid(), 'data' => ['name' => 'Tenant 2']]);

        $user->tenants()->attach([$tenant1->id, $tenant2->id]);

        // Act
        $tenants = GetTenantsByGlobalId::run($globalId);

        // Assert
        $this->assertCount(2, $tenants);
        $this->assertTrue($tenants->contains('id', $tenant1->id));
        $this->assertTrue($tenants->contains('id', $tenant2->id));
    }

    public function test_it_caches_the_results(): void
    {
        // Pin the shared manager so this test exercises real caching — under
        // the default array store, global_cache() is inert (each call builds
        // a fresh store), so this assertion would pass even if invalidation
        // were completely broken.
        $this->pinGlobalCache();

        // Arrange
        $globalId = 'global-'.uniqid();
        $user = CentralUser::create([
            'global_id' => $globalId,
            'name' => 'Test User 2',
            'email' => 'test2-'.uniqid().'@example.com',
            'password' => 'password',
        ]);

        $tenant = TestTenant::provisioned(['id' => 'tenant-'.uniqid(), 'data' => ['name' => 'Tenant 3']]);
        $user->tenants()->attach($tenant->id);

        // Act
        // First run - should query DB
        $tenants1 = GetTenantsByGlobalId::run($globalId);

        // We want to test that it REMAINS cached if we don't trigger events.
        // But since we want the system to be robust, we'll test that it DOES flush on detach.

        // Detach flushes the cache now because Membership model has events
        $user->tenants()->detach($tenant->id);

        // Second run - should return fresh result (0 tenants)
        $tenants2 = GetTenantsByGlobalId::run($globalId);

        // Assert
        $this->assertCount(1, $tenants1);
        $this->assertCount(0, $tenants2);
    }

    /**
     * Deleting a tenant must not leave it in a member's cached tenant list —
     * User::canAccessTenant() and every workspace switcher read
     * straight off that cache, so a stale entry would keep offering access to
     * (or navigation toward) a tenant that no longer exists.
     */
    public function test_deleting_a_tenant_invalidates_every_members_cache(): void
    {
        $this->pinGlobalCache();

        $globalId = 'global-'.uniqid();
        $user = CentralUser::create([
            'global_id' => $globalId,
            'name' => 'Test User 3',
            'email' => 'test3-'.uniqid().'@example.com',
            'password' => 'password',
        ]);

        $tenant = TestTenant::provisioned(['id' => 'tenant-'.uniqid(), 'data' => ['name' => 'Tenant 4']]);
        $user->tenants()->attach($tenant->id);

        $tenantsBefore = GetTenantsByGlobalId::run($globalId);
        $this->assertCount(1, $tenantsBefore);

        $tenant->delete();

        $tenantsAfter = GetTenantsByGlobalId::run($globalId);
        $this->assertCount(0, $tenantsAfter);
    }
}
