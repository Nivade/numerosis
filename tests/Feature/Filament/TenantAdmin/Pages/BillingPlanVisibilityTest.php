<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Tests\Feature\Filament\TenantAdmin\Pages;

use App\Models\Central\CentralUser;
use App\Models\Central\PaymentPlan;
use App\Models\Central\Subscription;
use App\Models\Central\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Livewire\Livewire;
use Nvade\Numerosis\Enums\Billing\BillingCycle;
use Nvade\Numerosis\Tests\TestCase;
use Nvade\NumerosisFilament\TenantAdmin\Pages\Billing;

class BillingPlanVisibilityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Config::set('tenancy.central_domains', ['localhost']);
    }

    public function test_active_plan_is_highlighted_with_badge(): void
    {
        $plan = PaymentPlan::factory()->create([
            'name' => 'Pro Plan',
            'slug' => 'pro',
            'monthly_price' => 2000,
            'yearly_price' => 20000,
            'monthly_id' => 'price_pro_monthly',
            'available' => true,
        ]);

        $tenant = Tenant::factory()->create();
        $user = CentralUser::factory()->create();
        $tenant->users()->attach($user, ['role' => 'owner']);

        // Create a subscription for the tenant
        Subscription::create([
            'user_id' => $user->id,
            'subscribable_id' => $tenant->id,
            'subscribable_type' => Tenant::class,
            'type' => 'default',
            'payment_plan_id' => $plan->id,
            'stripe_id' => 'sub_123',
            'stripe_status' => 'active',
            'stripe_price' => 'price_pro_monthly',
        ]);

        $tenant->run(function () use ($tenant, $user, $plan) {
            $this->actingAsTenantPanelUser($tenant, $user);

            Livewire::test(Billing::class)
                ->assertStatus(200)
                ->assertSee($plan->name)
                ->assertSee('Your Active Plan');
        });
    }

    public function test_defaults_to_yearly_cycle_if_subscribed_to_yearly_plan(): void
    {
        $plan = PaymentPlan::factory()->create([
            'name' => 'Pro Plan',
            'slug' => 'pro',
            'monthly_price' => 2000,
            'yearly_price' => 20000,
            'monthly_id' => 'price_pro_monthly',
            'yearly_id' => 'price_pro_yearly',
            'available' => true,
        ]);

        $tenant = Tenant::factory()->create();
        $user = CentralUser::factory()->create();
        $tenant->users()->attach($user, ['role' => 'owner']);

        // Create a YEARLY subscription for the tenant
        Subscription::create([
            'user_id' => $user->id,
            'subscribable_id' => $tenant->id,
            'subscribable_type' => Tenant::class,
            'type' => 'default',
            'payment_plan_id' => $plan->id,
            'stripe_id' => 'sub_123',
            'stripe_status' => 'active',
            'stripe_price' => 'price_pro_yearly',
        ]);

        $tenant->run(function () use ($tenant, $user, $plan) {
            $this->actingAsTenantPanelUser($tenant, $user);

            Livewire::test(Billing::class)
                ->assertStatus(200)
                ->assertSet('billingCycle', BillingCycle::Yearly)
                ->assertSee($plan->name)
                ->assertSee('Your Active Plan');
        });
    }
}
