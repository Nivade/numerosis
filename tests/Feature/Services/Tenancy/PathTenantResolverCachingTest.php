<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Tests\Feature\Services\Tenancy;

use App\Models\Central\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route;
use Nvade\Numerosis\Services\Tenancy\PreservingPathTenantResolver;
use Nvade\Numerosis\Tests\TestCase;
use Stancl\Tenancy\Resolvers\PathTenantResolver;

/**
 * `PathTenantResolver::$shouldCache` is a separate static on a separate class
 * from the domain resolver's, defaults to false, and nothing set it — so every
 * path-mode request paid the central lookup that `numerosis:install` warns
 * about losing for domain mode.
 */
class PathTenantResolverCachingTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_path_resolver_caches_like_the_domain_resolver(): void
    {
        $this->assertTrue(PreservingPathTenantResolver::$shouldCache);
    }

    public function test_resolving_the_same_tenant_twice_only_queries_once(): void
    {
        $tenant = Tenant::create(['id' => 'path-cache-'.uniqid()]);

        $resolver = resolve(PreservingPathTenantResolver::class);
        $resolver->resolve($this->routeFor($tenant->id));

        $connection = $this->centralDatabase();
        $connection->flushQueryLog();
        $connection->enableQueryLog();

        $resolved = $resolver->resolve($this->routeFor($tenant->id));

        $this->assertSame($tenant->id, $resolved->getTenantKey());
        $this->assertEmpty(
            $connection->getQueryLog(),
            'A second path resolution of the same tenant went back to the central database.'
        );
    }

    /**
     * The binding stancl's invalidator resolves and the one the middleware
     * resolves have to be the same instance, or the resolver writes to one
     * namespace while invalidation clears another.
     */
    public function test_the_bound_resolver_is_one_shared_instance(): void
    {
        $this->assertSame(
            resolve(PathTenantResolver::class),
            resolve(PreservingPathTenantResolver::class)
        );
    }

    /**
     * The base `getCacheKey()` json-encodes the `Route` it was handed, while
     * `getArgsForTenant()` hands invalidation the tenant id — so nothing
     * cached was ever forgotten. The override keys both on the id.
     */
    public function test_the_cache_key_matches_the_one_invalidation_clears(): void
    {
        $tenant = Tenant::create(['id' => 'path-cache-key-'.uniqid()]);

        $resolver = resolve(PreservingPathTenantResolver::class);

        $this->assertSame(
            $resolver->getCacheKey($tenant->id),
            $resolver->getCacheKey($this->routeFor($tenant->id))
        );
    }

    /**
     * `invalidateCache()` is what stancl calls from the tenant's `saved` and
     * `deleting` hooks; it has to clear the entry `resolve()` wrote.
     */
    public function test_invalidation_clears_what_resolution_wrote(): void
    {
        $tenant = Tenant::create(['id' => 'path-cache-inv-'.uniqid()]);

        $resolver = resolve(PreservingPathTenantResolver::class);
        $resolver->resolve($this->routeFor($tenant->id));

        $resolver->invalidateCache($tenant);

        $connection = $this->centralDatabase();
        $connection->flushQueryLog();
        $connection->enableQueryLog();

        $resolver->resolve($this->routeFor($tenant->id));

        $this->assertNotEmpty(
            $connection->getQueryLog(),
            'Invalidation cleared a different key from the one resolution wrote.'
        );
    }

    private function routeFor(string $tenantKey): Route
    {
        $parameter = PathTenantResolver::$tenantParameterName;

        $route = new Route(['GET'], '/{'.$parameter.'}/dashboard', fn () => null);
        $route->bind(request());
        $route->setParameter($parameter, $tenantKey);

        return $route;
    }
}
