<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Tests\Feature\Resolvers;

use Nvade\Numerosis\Models\Central\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Stancl\Tenancy\Exceptions\TenantCouldNotBeIdentifiedOnDomainException;
use Stancl\Tenancy\Resolvers\DomainTenantResolver;
use Nvade\Numerosis\Tests\TestCase;

class DomainTenantResolverCachingTest extends TestCase
{
    use RefreshDatabase;

    /**
     * DomainTenantResolver::$shouldCache is enabled in
     * TenancyServiceProvider::register() — without it every tenant-subdomain
     * request pays a whereHas('domains') query (with domains eagerly loaded)
     * before anything else happens.
     */
    public function test_resolving_a_domain_twice_only_queries_once(): void
    {
        $tenant = Tenant::create(['id' => 'resolver-cache-'.uniqid()]);
        $domain = $tenant->domains()->create([
            'id' => $tenant->id,
            'domain' => $this->tenantDomain($tenant->id),
        ]);

        $resolver = app(DomainTenantResolver::class);
        $resolver->resolve($domain->domain);

        DB::flushQueryLog();
        DB::enableQueryLog();

        $resolved = $resolver->resolve($domain->domain);

        $this->assertSame($tenant->id, $resolved->getTenantKey());
        $this->assertEmpty(
            DB::getQueryLog(),
            'A second resolution of the same domain should not have queried the database.'
        );
    }

    /**
     * Deleting a domain must invalidate the resolver's cache
     * ({@see \Nvade\Numerosis\Models\Central\Domain} uses
     * Stancl's InvalidatesTenantsResolverCache) — otherwise the cached
     * resolution keeps identifying requests as belonging to a tenant whose
     * domain no longer exists.
     */
    public function test_deleting_a_domain_invalidates_the_resolver_cache(): void
    {
        $tenant = Tenant::create(['id' => 'resolver-cache-del-'.uniqid()]);
        $domain = $tenant->domains()->create([
            'id' => $tenant->id,
            'domain' => $this->tenantDomain($tenant->id),
        ]);

        $resolver = app(DomainTenantResolver::class);
        $resolved = $resolver->resolve($domain->domain);
        $this->assertSame($tenant->id, $resolved->getTenantKey());

        $domain->delete();

        $this->expectException(TenantCouldNotBeIdentifiedOnDomainException::class);
        $resolver->resolve($domain->domain);
    }
}
