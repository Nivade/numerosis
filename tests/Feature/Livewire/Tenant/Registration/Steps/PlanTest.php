<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Tests\Feature\Livewire\Tenant\Registration\Steps;

use Nvade\Numerosis\Enums\BillingCycle;
use Nvade\Numerosis\Livewire\Tenant\Registration\Steps\Plan;
use Nvade\Numerosis\Models\Central\PaymentPlan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Nvade\Numerosis\Tests\TestCase;

class PlanTest extends TestCase
{
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

        $component->assertViewHas('paymentPlans', function ($plans) {
            return $plans->count() === 3;
        });

        // Set billing cycle to yearly
        $component->set('billingCycle', BillingCycle::Yearly);

        $component->assertViewHas('paymentPlans', function ($plans) {
            return $plans->count() === 3;
        });

        $component->assertSee('Plan 1')
            ->assertSee('Plan 2')
            ->assertSee('Plan 3');
    }
}
