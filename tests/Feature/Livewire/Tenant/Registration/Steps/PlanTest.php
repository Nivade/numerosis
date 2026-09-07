<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Tests\Feature\Livewire\Tenant\Registration\Steps;

use App\Models\Central\PaymentPlan;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Nvade\Numerosis\Enums\Billing\BillingCycle;
use Nvade\Numerosis\Livewire\Tenant\Registration\Steps\Plan;
use Nvade\Numerosis\Models\Central\PlanFeature;
use Nvade\Numerosis\Tests\Concerns\PinsGlobalCache;
use Nvade\Numerosis\Tests\TestCase;

class PlanTest extends TestCase
{
    use PinsGlobalCache;
    use RefreshDatabase;

    public function test_it_displays_all_available_plans_regardless_of_billing_cycle_toggle(): void
    {
        // Create 3 available plans
        PaymentPlan::factory()->create([
            'name' => 'Plan 1',
            'available' => true,
        ]);
        PaymentPlan::factory()->create([
            'name' => 'Plan 2',
            'available' => true,
        ]);
        PaymentPlan::factory()->create([
            'name' => 'Plan 3',
            'available' => true,
        ]);

        $component = Livewire::test(Plan::class);

        $component->assertViewHas('paymentPlans', fn ($plans) => $plans->count() === 3);

        // Set billing cycle to yearly
        $component->set('billingCycle', BillingCycle::Yearly);

        $component->assertViewHas('paymentPlans', fn ($plans) => $plans->count() === 3);

        $component->assertSee('Plan 1')
            ->assertSee('Plan 2')
            ->assertSee('Plan 3');
    }

    /**
     * Each card used to ask for its features three times — a `take(6)`, a
     * `count()`, and a `features()->get()` for a modal that is usually never
     * opened — none of them shared, because `available()` did not eager-load
     * the relation. Twelve queries for a four-plan catalogue, re-run on every
     * Livewire update of this step.
     */
    public function test_rendering_the_catalogue_reads_each_plans_features_once(): void
    {
        $this->pinGlobalCache();

        PaymentPlan::factory()->count(4)->create(['available' => true])
            ->each(fn (PaymentPlan $plan) => $plan->features()->attach(
                PlanFeature::factory()->count(8)->create()->pluck('id'),
                ['available' => true],
            ));

        $queries = [];
        DB::listen(function (QueryExecuted $query) use (&$queries): void {
            if (str_contains($query->sql, 'payment_plan_features')) {
                $queries[] = $query->sql;
            }
        });

        Livewire::test(Plan::class)->assertOk();

        $this->assertLessThanOrEqual(1, count($queries), 'The catalogue should read every plan\'s features in one eager load.');
    }

    /**
     * `cycle-toggle.blade.php` toggles with `$set('billingCycle', 'yearly')`,
     * a raw string, and the wizard re-mounts this step with the string
     * `StepComponent::dispatchDehydrated()` wrote. Both reach the property
     * uncast, which is why `cycle()` exists.
     */
    public function test_cycle_normalises_a_string_billing_cycle(): void
    {
        PaymentPlan::factory()->create(['available' => true]);

        $step = Livewire::test(Plan::class, ['billingCycle' => BillingCycle::Yearly->value])->instance();

        $this->assertInstanceOf(Plan::class, $step);
        $this->assertSame(BillingCycle::Yearly, $step->cycle());
    }

    public function test_cycle_falls_back_to_monthly_for_an_unrecognised_stored_value(): void
    {
        PaymentPlan::factory()->create(['available' => true]);

        $step = Livewire::test(Plan::class, ['billingCycle' => 'fortnightly'])->instance();

        $this->assertInstanceOf(Plan::class, $step);
        $this->assertSame(BillingCycle::Monthly, $step->cycle());
    }
}
