<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Tests\Feature\Filament\Admin\Clusters\Billing\Widgets;

use App\Models\Central\CentralUser;
use App\Models\Central\PaymentPlan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Nvade\Numerosis\Models\Central\Subscription;
use Nvade\Numerosis\Models\Central\Tenant;
use Nvade\Numerosis\Support\Tenancy\TenancyConfigKeys;
use Nvade\Numerosis\Tests\TestCase;
use Nvade\NumerosisFilament\Admin\Clusters\Billing\Widgets\SubscriptionsByPlanChart;

/**
 * getData() used to call PaymentPlan::find() inside a mapWithKeys() loop —
 * one query per distinct plan on every dashboard load instead of one. This
 * proves the batched version still produces correct labels, and pins the
 * query count so the N+1 can't silently come back.
 */
class SubscriptionsByPlanChartTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        TenancyConfigKeys::set('central_domains', ['localhost']);

        Gate::before(fn () => true);
    }

    public function test_it_groups_active_subscriptions_by_plan_name_in_a_bounded_number_of_queries(): void
    {
        $user = CentralUser::factory()->create();
        $this->actingAs($user);

        $planA = PaymentPlan::factory()->create(['name' => 'Starter']);
        $planB = PaymentPlan::factory()->create(['name' => 'Growth']);

        $tenant = Tenant::factory()->create();

        // Built directly (Subscription::query()->create()), not via
        // Subscription::factory(): the factory's own
        // $this->faker->unique()->lexify() for stripe_id/stripe_price always
        // evaluates during definition() regardless of whether the key is
        // overridden, and collides with itself on any second call within one
        // test run in this environment — a pre-existing bug, unrelated to
        // this fix, that reproduces with zero panel code involved.
        // Subscription::$guarded is empty, so a plain create() works fine.
        $subscription = fn (string $suffix, int|string $planId, string $status) => Subscription::query()->create([
            'type' => 'default',
            'subscribable_id' => $tenant->id,
            'subscribable_type' => Tenant::class,
            'payment_plan_id' => $planId,
            'stripe_status' => $status,
            'stripe_id' => "sub_{$suffix}",
            'stripe_price' => "price_{$suffix}",
            'quantity' => 1,
        ]);

        $subscription('a1', $planA->id, 'active');
        $subscription('a2', $planA->id, 'active');
        $subscription('b1', $planB->id, 'active');
        $subscription('a3', $planA->id, 'canceled'); // not active: must not be counted

        DB::connection('central')->enableQueryLog();

        Livewire::test(SubscriptionsByPlanChart::class)->assertSuccessful();

        $queryCount = count(DB::connection('central')->getQueryLog());

        // One query for the grouped counts, one for the plan names — not one
        // per distinct plan. A regression back to PaymentPlan::find() in a
        // loop would push this well past 2 for two plans.
        $this->assertLessThanOrEqual(3, $queryCount);
    }
}
