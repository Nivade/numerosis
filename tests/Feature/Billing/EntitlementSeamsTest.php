<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Tests\Feature\Billing;

use App\Models\Central\CentralUser;
use App\Models\Central\PaymentPlan;
use App\Models\Central\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Config;
use Nvade\Numerosis\Contracts\Billing\Entitlements;
use Nvade\Numerosis\Contracts\Billing\SeatPolicy;
use Nvade\Numerosis\Exceptions\Billing\EntitlementDenied;
use Nvade\Numerosis\Http\Middleware\EnsureEntitlement;
use Nvade\Numerosis\Models\Central\PlanFeature;
use Nvade\Numerosis\Models\Central\Subscription;
use Nvade\Numerosis\Services\Billing\SeatLimitPlanPolicy;
use Nvade\Numerosis\Tests\TestCase;
use Stancl\Tenancy\Contracts\Tenant as TenantContract;

/**
 * The middleware and the directive are presentation; the action check is the
 * gate. All three have to answer the same question the same way, which is what
 * this file holds down.
 */
class EntitlementSeamsTest extends TestCase
{
    use RefreshDatabase;

    public function test_all_three_seams_agree_on_the_same_capability(): void
    {
        $tenant = $this->tenantWithout('exports');

        tenancy()->initialize($tenant);

        $entitlements = resolve(Entitlements::class);

        $this->assertFalse($entitlements->allows('exports'));
        $this->assertFalse((bool) Blade::check('entitled', 'exports'));
        $this->assertSame(403, $this->refusalStatus('exports'));

        $this->assertTrue($entitlements->allows('reporting'));
        $this->assertTrue((bool) Blade::check('entitled', 'reporting'));
        $this->assertNull($this->refusalStatus('reporting'));

        tenancy()->end();
    }

    /**
     * A host swapping `numerosis.billing.implementations[Entitlements::class]`
     * has to reach the seat cap through that binding everywhere, including
     * `GetTenantSeatUsage`: this tenant carries no subscription at all, so a
     * seat cap re-derived from the plan (the pre-fix behaviour) reads as
     * uncapped and reports room that is not there. Only reading the cap
     * through the bound `Entitlements` sees the host's answer.
     */
    public function test_a_host_entitlements_implementation_is_what_the_seat_count_reads(): void
    {
        $tenant = $this->createTenantWithDomain('host-impl-'.substr(uniqid(), -8), 'Host Impl Tenant');
        tenancy()->end();

        $member = CentralUser::factory()->create();
        $tenant->users()->attach($member->global_id, ['role' => 'member']);

        $fake = new class implements Entitlements
        {
            public function allows(string $capability, ?TenantContract $tenant = null): bool
            {
                return true;
            }

            public function limit(string $capability, ?TenantContract $tenant = null): int
            {
                return 1;
            }

            public function used(string $capability, ?TenantContract $tenant = null): int
            {
                return 1;
            }

            public function remaining(string $capability, ?TenantContract $tenant = null): int
            {
                return 0;
            }

            public function consume(string $capability, int $amount = 1, ?TenantContract $tenant = null): int
            {
                return $amount;
            }

            public function assertAllowed(string $capability, ?TenantContract $tenant = null): void {}
        };

        app()->bind(Entitlements::class, fn (): Entitlements => $fake);

        $policy = resolve(SeatPolicy::class);

        $this->assertInstanceOf(SeatLimitPlanPolicy::class, $policy);
        $this->assertFalse($policy->hasSeatForNewMember($tenant));
    }

    /**
     * @return int|null The status the middleware refused with, or null when it let the request through.
     */
    private function refusalStatus(string $capability): ?int
    {
        $middleware = resolve(EnsureEntitlement::class);

        try {
            $middleware->handle(request(), fn (): string => 'passed', $capability);
        } catch (EntitlementDenied) {
            return 403;
        }

        return null;
    }

    private function tenantWithout(string $capability): Tenant
    {
        Config::set('numerosis.billing.free_tier.capabilities', []);

        $plan = PaymentPlan::factory()->create([
            'slug' => 'seams-'.substr(uniqid(), -6),
            'available' => true,
        ]);

        foreach (['reporting' => true, $capability => false] as $slug => $available) {
            $feature = PlanFeature::query()->firstOrCreate(['slug' => $slug], ['description' => $slug]);

            $plan->features()->attach($feature->id, ['available' => $available]);
        }

        $tenant = $this->createTenantWithDomain('seam'.substr(uniqid(), -8), 'Seam Tenant');

        tenancy()->end();

        Subscription::query()->create([
            'subscribable_id' => $tenant->getKey(),
            'subscribable_type' => $tenant->getMorphClass(),
            'type' => 'default',
            'stripe_id' => 'sub_'.substr(uniqid(), -10),
            'stripe_status' => 'active',
            'payment_plan_id' => $plan->id,
        ]);

        return $tenant;
    }
}
