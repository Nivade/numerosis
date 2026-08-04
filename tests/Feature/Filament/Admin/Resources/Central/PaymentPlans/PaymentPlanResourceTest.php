<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Tests\Feature\Filament\Admin\Resources\Central\PaymentPlans;

use Nvade\Numerosis\Filament\Admin\Resources\Central\PaymentPlans\PaymentPlanResource;
use Nvade\Numerosis\Models\Central\CentralUser;
use Nvade\Numerosis\Models\Central\PaymentPlan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Nvade\Numerosis\Tests\TestCase;

class PaymentPlanResourceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['tenancy.central_domains' => ['localhost']]);

        Gate::before(fn () => true);
    }

    public function test_can_render_page(): void
    {
        $user = CentralUser::factory()->create();
        $this->actingAs($user);

        $this->get(PaymentPlanResource::getUrl('index'))
            ->assertStatus(200);
    }

    public function test_can_list_payment_plans(): void
    {
        $user = CentralUser::factory()->create();
        $this->actingAs($user);

        $plans = PaymentPlan::factory()->count(5)->create();

        Livewire::test(\Nvade\Numerosis\Filament\Admin\Resources\Central\PaymentPlans\Pages\ListPaymentPlans::class)
            ->assertCanSeeTableRecords($plans);
    }
}
