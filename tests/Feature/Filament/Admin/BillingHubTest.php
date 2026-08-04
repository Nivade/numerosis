<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Tests\Feature\Filament\Admin;

use Nvade\Numerosis\Filament\Admin\Clusters\Billing\Pages\BillingDashboard;
use Nvade\Numerosis\Filament\Admin\Resources\Central\Features\FeatureResource;
use Nvade\Numerosis\Filament\Admin\Resources\Central\PaymentPlans\PaymentPlanResource;
use Nvade\Numerosis\Filament\Admin\Resources\Central\Subscriptions\SubscriptionResource;
use Nvade\Numerosis\Models\Central\CentralUser as User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Nvade\Numerosis\Tests\TestCase;

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
        \Illuminate\Support\Facades\Gate::before(fn () => true);

        $this->get(BillingDashboard::getUrl())
            ->assertSuccessful();
    }

    public function test_can_access_payment_plans_resource(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        \Illuminate\Support\Facades\Gate::before(fn () => true);

        $this->get(PaymentPlanResource::getUrl('index'))
            ->assertSuccessful();
    }

    public function test_can_access_features_resource(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        \Illuminate\Support\Facades\Gate::before(fn () => true);

        $this->get(FeatureResource::getUrl('index'))
            ->assertSuccessful();
    }

    public function test_can_access_subscriptions_resource(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        \Illuminate\Support\Facades\Gate::before(fn () => true);

        $this->get(SubscriptionResource::getUrl('index'))
            ->assertSuccessful();
    }
}
