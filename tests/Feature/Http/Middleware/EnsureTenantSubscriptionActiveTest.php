<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Tests\Feature\Http\Middleware;

use App\Models\Central\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Nvade\Numerosis\Http\Middleware\EnsureTenantSubscriptionActive;
use Nvade\Numerosis\Tests\TestCase;

class EnsureTenantSubscriptionActiveTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_lets_an_active_tenant_through(): void
    {
        Tenant::unsetEventDispatcher();
        $tenant = Tenant::factory()->create();

        $tenant->run(function () {
            $middleware = new EnsureTenantSubscriptionActive;

            $response = $middleware->handle(Request::create('/'), fn ($request) => new Response('ok'));

            $this->assertSame('ok', $response->getContent());
        });
    }

    public function test_it_redirects_a_suspended_tenant_to_the_suspended_page(): void
    {
        Tenant::unsetEventDispatcher();
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
        Tenant::unsetEventDispatcher();
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
}
