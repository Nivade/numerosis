<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Tests\Feature\Services\Billing;

use App\Models\Central\CentralUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Nvade\Numerosis\Services\Billing\TenantOrUserBillableResolver;
use Nvade\Numerosis\Tests\Support\HostSubscribableUser;
use Nvade\Numerosis\Tests\Support\TestTenant;
use Nvade\Numerosis\Tests\TestCase;

class TenantOrUserBillableResolverTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_resolves_the_authenticated_central_user(): void
    {
        $user = CentralUser::factory()->create();
        $this->actingAs($user);

        $this->assertTrue(resolve(TenantOrUserBillableResolver::class)->resolve()?->is($user));
    }

    /**
     * `PlanPolicy::assertEligible()` takes a `Subscribable`, so dropping a
     * host user that implements it but not the Cashier slice of
     * `BillableUser` would silently skip the eligibility check rather than
     * fail the checkout. The paths that need more than `Subscribable` narrow
     * on `BillableUser` themselves and throw.
     */
    public function test_it_resolves_a_host_user_that_is_subscribable_but_not_a_billable_user(): void
    {
        $user = new HostSubscribableUser;

        $this->actingAs($user);

        $this->assertSame($user, resolve(TenantOrUserBillableResolver::class)->resolve());
    }

    public function test_it_resolves_the_current_tenant_inside_tenancy(): void
    {
        $tenant = TestTenant::provisioned();

        tenancy()->initialize($tenant);

        try {
            $this->assertTrue(resolve(TenantOrUserBillableResolver::class)->resolve()?->is($tenant));
        } finally {
            tenancy()->end();
        }
    }
}
