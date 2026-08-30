<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Tests\Feature\Resolvers;

use App\Models\Central\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route;
use Nvade\Numerosis\Resolvers\PreservingPathTenantResolver;
use Nvade\Numerosis\Support\Tenancy\TenancyVersion;
use Nvade\Numerosis\Tests\TestCase;

/**
 * Path mode's full HTTP round trip is not testable from console — see
 * `.claude/rules/identification-modes.md` and `.claude/rules/filament-tenancy.md`
 * for why (`shouldRegisterPanel()`'s console exemption makes route-match
 * outcome unassertable). These tests deliberately drive the resolver
 * directly instead, which is enough to cover the part that actually broke.
 *
 * What broke: the override read `PathTenantResolver::$tenantParameterName`,
 * a v3 public static **property** that dev-master replaced with a static
 * **method**. On dev-master that is a fatal `Error: Access to undeclared
 * static property`, not a soft failure — and nothing reached it, because the
 * only caller is an HTTP request in path mode. Found by running PHPStan
 * against the dev-master leg, not by the suite.
 */
class PreservingPathTenantResolverTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_resolves_a_tenant_from_the_route_parameter(): void
    {
        $tenant = Tenant::create(['id' => 'path-resolver-'.uniqid()]);

        $resolved = resolve(PreservingPathTenantResolver::class)
            ->resolve($this->routeWithTenantParameter($tenant->id));

        $this->assertSame($tenant->id, $resolved->getTenantKey());
    }

    /**
     * The whole reason this subclass exists: stancl forgets the parameter,
     * Filament's own `IdentifyTenant` reads it later in the same request.
     */
    public function test_it_leaves_the_tenant_route_parameter_in_place(): void
    {
        $tenant = Tenant::create(['id' => 'path-resolver-'.uniqid()]);
        $route = $this->routeWithTenantParameter($tenant->id);

        resolve(PreservingPathTenantResolver::class)->resolve($route);

        $this->assertSame(
            $tenant->id,
            $route->parameter(TenancyVersion::pathTenantParameterName())
        );
    }

    /**
     * Guards the version difference itself rather than its consequence: this
     * fails on whichever leg reads the member the installed version does not
     * have, which is the failure the resolver could not surface on its own.
     */
    public function test_the_parameter_name_resolves_on_the_installed_version(): void
    {
        $this->assertSame('tenant', TenancyVersion::pathTenantParameterName());
    }

    private function routeWithTenantParameter(string $tenantKey): Route
    {
        $parameter = TenancyVersion::pathTenantParameterName();

        $route = new Route(['GET'], '/{'.$parameter.'}/dashboard', fn () => null);
        $route->bind(request());
        $route->setParameter($parameter, $tenantKey);

        return $route;
    }
}
