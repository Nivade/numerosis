<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Tests\Feature\Http\Middleware;

use App\Models\Central\Tenant;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Nvade\Numerosis\Actions\Tenancy\CreateTenantDomain;
use Nvade\Numerosis\Http\Middleware\InitializeTenancyByTenantDomain;
use Nvade\Numerosis\Models\Central\Tenant as BaseTenant;
use Nvade\Numerosis\Tests\Support\TestTenant;
use Nvade\Numerosis\Tests\TestCase;
use Stancl\Tenancy\Contracts\TenantCouldNotBeIdentifiedException;
use Stancl\Tenancy\Middleware\InitializeTenancyByDomainOrSubdomain;
use Stancl\Tenancy\Resolvers\DomainTenantResolver;
use Stancl\Tenancy\Tenancy;

/**
 * The suite's own harness serves the central app from `central.numerosistest.test`
 * — a host *below* the apex — so a tenant host does not end with the central
 * domain. An ordinary deployment has APP_URL at the apex, and every tenant
 * host then does. That difference decides which resolver stancl's
 * `InitializeTenancyByDomainOrSubdomain` delegates to, so every case here
 * pins the apex arrangement rather than the harness's.
 */
class InitializeTenancyByTenantDomainTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('numerosis.domains.apex', 'apexonly.test');
        config()->set('numerosis.domains.central', 'apexonly.test');
        config()->set('numerosis.domains.tenant_pattern', '{tenant}.apexonly.test');
        config()->set('tenancy.central_domains', ['apexonly.test']);
    }

    public function test_it_identifies_a_tenant_on_a_subdomain_of_the_central_domain(): void
    {
        $tenant = $this->tenantWithDomain();

        $this->handle($this->middleware()->handle(...), "http://{$tenant->id}.apexonly.test/");

        $this->assertTrue(tenancy()->initialized);

        $initialized = app(Tenancy::class)->tenant;
        $this->assertInstanceOf(Tenant::class, $initialized);
        $this->assertSame($tenant->id, $initialized->getTenantKey());
    }

    /**
     * The negative control, and the reason this middleware exists:
     * `CreateTenantDomain` writes `acme.apexonly.test`, while stancl's own
     * dispatcher sees a host ending with a central domain, hands over to the
     * label-only subdomain resolver and looks `acme` up instead.
     */
    public function test_stancls_own_dispatcher_cannot_identify_the_same_tenant(): void
    {
        $tenant = $this->tenantWithDomain();

        $this->expectException(TenantCouldNotBeIdentifiedException::class);

        $this->handle((new InitializeTenancyByDomainOrSubdomain)->handle(...), "http://{$tenant->id}.apexonly.test/");
    }

    public function test_it_leaves_the_central_domain_alone(): void
    {
        $this->tenantWithDomain();

        $this->handle($this->middleware()->handle(...), 'http://apexonly.test/');

        $this->assertFalse(tenancy()->initialized);
    }

    private function middleware(): InitializeTenancyByTenantDomain
    {
        return new InitializeTenancyByTenantDomain(app(Tenancy::class), app(DomainTenantResolver::class));
    }

    private function tenantWithDomain(): BaseTenant
    {
        $tenant = TestTenant::provisioned();
        CreateTenantDomain::run($tenant, (string) $tenant->getTenantKey());

        return $tenant;
    }

    private function handle(callable $middleware, string $url): void
    {
        $request = Request::create($url);

        /** @var Closure(Request): Response $next */
        $next = fn (Request $request): Response => new Response('ok');

        $response = $middleware($request, $next);

        $this->assertInstanceOf(Response::class, $response);
        $this->assertSame('ok', $response->getContent());
    }
}
