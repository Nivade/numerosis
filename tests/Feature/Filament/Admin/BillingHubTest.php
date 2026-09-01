<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Tests\Feature\Filament\Admin;

use App\Models\Central\CentralUser as User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Nvade\Numerosis\Tests\TestCase;
use Nvade\NumerosisFilament\Admin\Clusters\Billing\Pages\BillingDashboard;
use Nvade\NumerosisFilament\Admin\Resources\Central\PaymentPlans\PaymentPlanResource;
use Nvade\NumerosisFilament\Admin\Resources\Central\PlanFeatures\PlanFeatureResource;
use Nvade\NumerosisFilament\Admin\Resources\Central\Subscriptions\SubscriptionResource;

class BillingHubTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public function test_can_access_billing_dashboard(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        // Bypass authorization for testing
        $this->withoutExceptionHandling();
        Gate::before(fn () => true);

        $this->get(BillingDashboard::getUrl())
            ->assertSuccessful();
    }

    public function test_can_access_payment_plans_resource(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        Gate::before(fn () => true);

        $this->get(PaymentPlanResource::getUrl('index'))
            ->assertSuccessful();
    }

    public function test_can_access_features_resource(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        Gate::before(fn () => true);

        $this->get(PlanFeatureResource::getUrl('index'))
            ->assertSuccessful();
    }

    public function test_can_access_subscriptions_resource(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        Gate::before(fn () => true);

        $this->get(SubscriptionResource::getUrl('index'))
            ->assertSuccessful();
    }
}
