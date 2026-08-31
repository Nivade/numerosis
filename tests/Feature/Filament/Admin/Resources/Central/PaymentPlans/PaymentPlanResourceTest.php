<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Tests\Feature\Filament\Admin\Resources\Central\PaymentPlans;

use App\Models\Central\CentralUser;
use App\Models\Central\PaymentPlan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Nvade\Numerosis\Models\Central\Subscription;
use Nvade\Numerosis\Support\Tenancy\TenancyConfigKeys;
use Nvade\Numerosis\Tests\TestCase;
use Nvade\NumerosisFilament\Admin\Resources\Central\PaymentPlans\Pages\ListPaymentPlans;
use Nvade\NumerosisFilament\Admin\Resources\Central\PaymentPlans\Pages\ViewPaymentPlan;
use Nvade\NumerosisFilament\Admin\Resources\Central\PaymentPlans\PaymentPlanResource;

class PaymentPlanResourceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        TenancyConfigKeys::set('central_domains', ['localhost']);

        Gate::before(fn () => true);
    }

    public function test_can_render_page(): void
    {
        $user = CentralUser::factory()->create();
        $this->actingAs($user);

        $this->get(PaymentPlanResource::getUrl('index'))->assertOk();
    }

    public function test_can_list_payment_plans(): void
    {
        $user = CentralUser::factory()->create();
        $this->actingAs($user);

        $plans = PaymentPlan::factory()->count(5)->create();

        Livewire::test(ListPaymentPlans::class)
            ->assertCanSeeTableRecords($plans);
    }

    /**
     * The command-center detail page this resource used to entirely lack —
     * proves it renders and that the "who's on this plan" subscriber counts
     * this page adds actually count real subscriptions, not just zero.
     */
    public function test_view_page_shows_subscriber_counts(): void
    {
        $user = CentralUser::factory()->create();
        $this->actingAs($user);

        $plan = PaymentPlan::factory()->create(['name' => 'Growth']);
        // subscribable_id points at nothing real on purpose — this test only
        // needs rows in `subscriptions` for the count query, not a
        // provisioned tenant database. subscribable_type stays the real
        // Tenant class so the MorphTo itself resolves fine; it just finds no
        // matching row.
        Subscription::factory()->count(2)->create([
            'payment_plan_id' => $plan->id,
            'subscribable_id' => 'nonexistent',
            'stripe_status' => 'active',
        ]);
        Subscription::factory()->create([
            'payment_plan_id' => $plan->id,
            'subscribable_id' => 'nonexistent',
            'stripe_status' => 'past_due',
        ]);

        Livewire::test(ViewPaymentPlan::class, ['record' => $plan->getKey()])
            ->assertSuccessful()
            ->assertSee('Growth')
            ->assertSee('2')
            ->assertSee('1');
    }
}
