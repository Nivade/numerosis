<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Tests\Feature\Http\Middleware;

use App\Models\Central\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Nvade\Numerosis\Actions\Tenancy\CreateTenantDomain;
use Nvade\Numerosis\Http\Middleware\EnsureTenantSubscriptionActive;
use Nvade\Numerosis\Tests\TestCase;

class EnsureTenantSubscriptionActiveTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The three tests below this one instantiate the middleware and call
     * `handle()` directly, which is why deleting `packages/filament` — the
     * only thing that ever registered this middleware — left every one of
     * them green while suspension enforcement was switched off entirely.
     * The route-level tests at the bottom of this file are the ones that
     * fail if the `tenancy.subscription` alias stops being applied.
     */
    protected function defineRoutes($router): void
    {
        // Written as the host's own `routes/tenant.php` rather than added
        // after the fact: route registration has already happened by the time
        // a test body runs, so a `Route::get()` in setUp() would land outside
        // every group core built and prove nothing about the real stack.
        //
        // No `tenancy.auth:tenant` here on purpose. The gate reads `tenant()`
        // and never the user, so auth is not a precondition — and an auth
        // redirect for an unauthenticated request would mask the very
        // redirect under test.
        File::ensureDirectoryExists(base_path('routes'));
        File::put(base_path('routes/tenant.php'), <<<'PHP'
            <?php

            use Illuminate\Support\Facades\Route;

            Route::middleware('tenancy.subscription')->group(function (): void {
                Route::get('gated-probe', fn (): string => 'through')->name('tenant.gated-probe');
            });
            PHP);

        parent::defineRoutes($router);
    }

    protected function tearDown(): void
    {
        File::delete(base_path('routes/tenant.php'));

        parent::tearDown();
    }

    public function test_it_lets_an_active_tenant_through(): void
    {
        $tenant = Tenant::factory()->create();

        $tenant->run(function () {
            $middleware = new EnsureTenantSubscriptionActive;

            $response = $middleware->handle(Request::create('/'), fn ($request) => new Response('ok'));

            $this->assertSame('ok', $response->getContent());
        });
    }

    public function test_it_redirects_a_suspended_tenant_to_the_suspended_page(): void
    {
        $tenant = Tenant::factory()->create(['suspended_at' => now()]);

        $tenant->run(function () {
            $middleware = new EnsureTenantSubscriptionActive;

            $response = $middleware->handle(Request::create('/'), fn ($request) => new Response('ok'));

            $this->assertInstanceOf(RedirectResponse::class, $response);
            $this->assertSame(route('tenant.suspended'), $response->getTargetUrl());
        });
    }

    /**
     * The trial-expiry case: Starter provisions a tenant with zero money
     * collected, and today (before this middleware) that tenant kept
     * working forever once the trial ended and the card failed. Suspension
     * has nothing card-specific about it; this just confirms the gate
     * closes that hole the same way for any suspended tenant.
     */
    public function test_it_closes_the_trial_expiry_gap(): void
    {
        $tenant = Tenant::factory()->create([
            'trial_ends_at' => now()->subDay(),
            'suspended_at' => now(),
        ]);

        $tenant->run(function () {
            $middleware = new EnsureTenantSubscriptionActive;

            $response = $middleware->handle(Request::create('/'), fn ($request) => new Response('ok'));

            $this->assertInstanceOf(RedirectResponse::class, $response);
        });
    }

    public function test_a_real_request_to_a_gated_route_passes_for_an_active_tenant(): void
    {
        $tenant = Tenant::factory()->create();
        CreateTenantDomain::run($tenant, $tenant->id);

        $this->get('http://'.$this->tenantDomain($tenant->id).'/gated-probe')
            ->assertOk()
            ->assertSee('through');
    }

    /**
     * The regression this file was missing. Asserted through the HTTP kernel,
     * not against the class: what broke was the *registration*, and a
     * middleware nobody applies passes every unit test it has.
     */
    public function test_a_real_request_to_a_gated_route_bounces_a_suspended_tenant(): void
    {
        $tenant = Tenant::factory()->create(['suspended_at' => now()]);
        CreateTenantDomain::run($tenant, $tenant->id);

        $this->get('http://'.$this->tenantDomain($tenant->id).'/gated-probe')
            ->assertRedirectToRoute('tenant.suspended');
    }

    /**
     * The loop guard. `tenant.suspended` is where this middleware redirects,
     * and it is itself a tenant route — so registering the gate on the
     * `tenant` middleware group, rather than on the nested group inside it,
     * would redirect the redirect target to itself forever.
     */
    public function test_the_suspended_page_is_not_itself_gated(): void
    {
        $route = Route::getRoutes()->getByName('tenant.suspended');

        $this->assertNotNull($route);
        $this->assertNotContains('tenancy.subscription', $route->gatherMiddleware());
        $this->assertNotContains(EnsureTenantSubscriptionActive::class, $route->gatherMiddleware());
    }
}
