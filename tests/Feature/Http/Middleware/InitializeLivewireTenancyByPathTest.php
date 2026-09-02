<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Tests\Feature\Http\Middleware;

use App\Models\Central\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Nvade\Numerosis\Http\Middleware\InitializeLivewireTenancyByPath;
use Nvade\Numerosis\Tests\TestCase;
use Stancl\Tenancy\Tenancy;

/**
 * `Livewire::setUpdateRoute()` registers one global `/livewire/update` route
 * with no `{tenant}` parameter, so under path identification mode
 * `Stancl\Tenancy\Middleware\InitializeTenancyByPath` can't run against it —
 * it asserts `$route->parameterNames()[0] === 'tenant'`, and index 0 doesn't
 * exist on a parameterless route. This class reads the tenant off the
 * `Referer` header instead, since that's the page the commit actually came
 * from. Found via `tests/Browser/ModuleMarketplaceTest`'s purchase-modal
 * case, which never mounted under path mode for exactly this reason.
 */
class InitializeLivewireTenancyByPathTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_initializes_the_tenant_named_by_the_referer_path(): void
    {
        $tenant = Tenant::factory()->create();

        $middleware = new InitializeLivewireTenancyByPath(app(Tenancy::class));

        $request = Request::create('/livewire/update', 'POST');
        $request->headers->set('referer', "http://central.test/{$tenant->id}/marketplace");

        $response = $middleware->handle($request, fn (Request $request): Response => new Response('ok'));

        $this->assertInstanceOf(Response::class, $response);
        $this->assertSame('ok', $response->getContent());
        $this->assertTrue(tenancy()->initialized);

        $initializedTenant = app(Tenancy::class)->tenant;
        $this->assertInstanceOf(Tenant::class, $initializedTenant);
        $this->assertSame($tenant->id, $initializedTenant->getTenantKey());
    }

    public function test_it_leaves_tenancy_uninitialized_when_the_referer_names_no_real_tenant(): void
    {
        $middleware = new InitializeLivewireTenancyByPath(app(Tenancy::class));

        $request = Request::create('/livewire/update', 'POST');
        $request->headers->set('referer', 'http://central.test/no-such-tenant/marketplace');

        $response = $middleware->handle($request, fn (Request $request): Response => new Response('ok'));

        $this->assertInstanceOf(Response::class, $response);
        $this->assertSame('ok', $response->getContent());
        $this->assertFalse(tenancy()->initialized);
    }

    /**
     * The degradation case for a central Livewire commit — the update route
     * is global, so it also carries every central page's commits (e.g. a
     * central admin panel action) under path mode, and those must not be
     * mistaken for a tenant request.
     */
    public function test_it_leaves_tenancy_uninitialized_with_no_referer_at_all(): void
    {
        $middleware = new InitializeLivewireTenancyByPath(app(Tenancy::class));

        $request = Request::create('/livewire/update', 'POST');

        $response = $middleware->handle($request, fn (Request $request): Response => new Response('ok'));

        $this->assertInstanceOf(Response::class, $response);
        $this->assertSame('ok', $response->getContent());
        $this->assertFalse(tenancy()->initialized);
    }
}
