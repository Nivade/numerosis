<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Tests\Feature\Billing;

use App\Models\Central\CentralUser;
use App\Models\Central\PaymentPlan;
use App\Models\Central\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Nvade\Numerosis\Contracts\Billing\Entitlements;
use Nvade\Numerosis\Enums\Billing\SubscriptionStatus;
use Nvade\Numerosis\Exceptions\Billing\EntitlementDenied;
use Nvade\Numerosis\Models\Central\PaymentPlan as BasePaymentPlan;
use Nvade\Numerosis\Models\Central\PlanFeature;
use Nvade\Numerosis\Models\Central\Subscription;
use Nvade\Numerosis\Models\Central\Tenant as BaseTenant;
use Nvade\Numerosis\Services\Billing\PlanEntitlements;
use Nvade\Numerosis\Tests\TestCase;

/**
 * The plan a customer bought described what they got and controlled nothing;
 * every assertion here is about the difference.
 */
class EntitlementsTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_capability_on_the_plan_is_allowed_and_one_off_it_is_not(): void
    {
        $tenant = $this->tenantOn($this->planWith(['reporting'], ['exports']));

        $this->assertTrue($this->entitlements()->allows('reporting', $tenant));
        $this->assertFalse($this->entitlements()->allows('exports', $tenant));
    }

    public function test_a_tenant_with_no_subscription_falls_back_to_the_free_tier_and_never_throws(): void
    {
        Config::set('numerosis.billing.free_tier.capabilities', ['reporting']);
        Config::set('numerosis.billing.free_tier.limits', ['seats' => 1]);

        $tenant = Tenant::factory()->create();

        $this->assertTrue($this->entitlements()->allows('reporting', $tenant));
        $this->assertFalse($this->entitlements()->allows('exports', $tenant));
        $this->assertSame(1, $this->entitlements()->limit('seats', $tenant));
    }

    public function test_a_past_due_subscription_still_resolves_rather_than_throwing(): void
    {
        $tenant = $this->tenantOn($this->planWith(['reporting']), SubscriptionStatus::PastDue);

        // `past_due` is not a valid subscription, so this is the free tier —
        // the point being that dunning answers rather than throws.
        $this->assertFalse($this->entitlements()->allows('reporting', $tenant));
    }

    public function test_a_trialing_subscription_keeps_the_plans_capabilities(): void
    {
        $tenant = $this->tenantOn($this->planWith(['reporting']), SubscriptionStatus::Trialing);

        $this->assertTrue($this->entitlements()->allows('reporting', $tenant));
    }

    public function test_consuming_an_allowance_counts_and_then_refuses(): void
    {
        $plan = $this->planWith(['api-calls'], limits: ['api-calls' => 2]);
        $tenant = $this->tenantOn($plan);

        $this->assertSame(1, $this->entitlements()->consume('api-calls', 1, $tenant));
        $this->assertSame(2, $this->entitlements()->consume('api-calls', 1, $tenant));
        $this->assertSame(0, $this->entitlements()->remaining('api-calls', $tenant));

        $this->expectException(EntitlementDenied::class);

        $this->entitlements()->consume('api-calls', 1, $tenant);
    }

    public function test_a_refusal_names_the_plan_that_would_allow_it(): void
    {
        $cheap = $this->planWith(['reporting'], monthlyPrice: 1_000);
        $bigger = $this->planWith(['reporting', 'exports'], monthlyPrice: 5_000, slug: 'bigger');

        $tenant = $this->tenantOn($cheap);

        try {
            $this->entitlements()->assertAllowed('exports', $tenant);

            $this->fail('An excluded capability was allowed.');
        } catch (EntitlementDenied $denied) {
            $this->assertSame('exports', $denied->capability);
            $this->assertSame($bigger->slug, $denied->upgradeToPlan);
        }
    }

    /** Two tenants in one request cycle: the memo is per tenant or it is a leak. */
    public function test_entitlements_do_not_leak_between_tenants(): void
    {
        $withReporting = $this->tenantOn($this->planWith(['reporting'], slug: 'with'));
        $without = $this->tenantOn($this->planWith(['exports'], slug: 'without'));

        $entitlements = $this->entitlements();

        $this->assertTrue($entitlements->allows('reporting', $withReporting));
        $this->assertFalse($entitlements->allows('reporting', $without));
        $this->assertTrue($entitlements->allows('exports', $without));
    }

    public function test_a_downgrade_below_current_usage_leaves_remaining_at_zero_rather_than_negative(): void
    {
        $plan = $this->planWith(['reporting'], limits: ['seats' => 1]);
        $tenant = $this->tenantOn($plan);

        $this->createTenantUsers($tenant, 3);

        $this->assertSame(0, $this->entitlements()->remaining(PlanEntitlements::SEATS, $tenant));
        $this->assertGreaterThan(1, $this->entitlements()->used(PlanEntitlements::SEATS, $tenant));
    }

    private function entitlements(): Entitlements
    {
        return resolve(Entitlements::class);
    }

    /**
     * @param  list<string>  $available
     * @param  list<string>  $unavailable
     * @param  array<string, int>  $limits
     */
    private function planWith(array $available, array $unavailable = [], array $limits = [], int $monthlyPrice = 1_000, string $slug = 'entitled'): BasePaymentPlan
    {
        $plan = PaymentPlan::factory()->create([
            'slug' => $slug.'-'.substr(uniqid(), -6),
            'monthly_price' => $monthlyPrice,
            'available' => true,
            'metadata' => $limits === [] ? null : ['options' => ['limits' => $limits]],
        ]);

        // The slug is what an entitlement check names, so the pivot's
        // `available` flag is the whole subject here.
        foreach ([...array_map(fn (string $s): array => [$s, true], $available), ...array_map(fn (string $s): array => [$s, false], $unavailable)] as [$featureSlug, $isAvailable]) {
            $feature = PlanFeature::query()->firstOrCreate(['slug' => $featureSlug], ['description' => $featureSlug]);

            $plan->features()->attach($feature->id, ['available' => $isAvailable]);
        }

        return $plan;
    }

    private function tenantOn(BasePaymentPlan $plan, SubscriptionStatus $status = SubscriptionStatus::Active): BaseTenant
    {
        $tenant = Tenant::factory()->create();

        Subscription::query()->create([
            'subscribable_id' => $tenant->getKey(),
            'subscribable_type' => $tenant->getMorphClass(),
            'type' => 'default',
            'stripe_id' => 'sub_'.substr(uniqid(), -10),
            'stripe_status' => $status->value,
            'payment_plan_id' => $plan->id,
            'trial_ends_at' => $status === SubscriptionStatus::Trialing ? now()->addDays(7) : null,
        ]);

        return $tenant;
    }

    private function createTenantUsers(BaseTenant $tenant, int $count): void
    {
        for ($index = 0; $index < $count; $index++) {
            $user = CentralUser::factory()->create();

            $tenant->users()->attach($user->global_id, ['role' => 'member']);
        }
    }
}
