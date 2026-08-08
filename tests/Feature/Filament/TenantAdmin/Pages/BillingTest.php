<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Tests\Feature\Filament\TenantAdmin\Pages;

use App\Models\Central\CentralUser;
use App\Models\Central\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Nvade\Numerosis\Filament\TenantAdmin\Pages\Billing;
use Nvade\Numerosis\Models\Central\PaymentPlan;
use Nvade\Numerosis\Tests\TestCase;

class BillingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['tenancy.central_domains' => ['localhost']]);
    }

    public function test_can_render_billing_page(): void
    {
        PaymentPlan::factory()->create([
            'name' => 'Basic Plan',
            'slug' => 'basic',
            'monthly_price' => 1000,
            'yearly_price' => 10000,
            'available' => true,
        ]);

        $tenant = Tenant::factory()->create();
        $user = CentralUser::factory()->create();
        $tenant->users()->attach($user, ['role' => 'owner']);

        $tenant->run(function () use ($tenant, $user) {
            $this->actingAsTenantPanelUser($tenant, $user);

            $component = Livewire::test(Billing::class);
            $component->assertStatus(200)
                ->assertSee('Subscription Overview')
                ->assertSee('Available Plans');
        });
    }

    public function test_non_owner_cannot_access_billing_page(): void
    {
        PaymentPlan::factory()->create([
            'name' => 'Basic Plan',
            'slug' => 'basic',
            'monthly_price' => 1000,
            'yearly_price' => 10000,
            'available' => true,
        ]);

        $tenant = Tenant::factory()->create();
        $owner = CentralUser::factory()->create();
        $tenant->users()->attach($owner, ['role' => 'owner']);

        $nonOwner = CentralUser::factory()->create();
        $tenant->users()->attach($nonOwner, ['role' => 'admin']);

        $tenant->run(function () use ($tenant, $nonOwner) {
            $this->actingAsTenantPanelUser($tenant, $nonOwner);

            $component = Livewire::test(Billing::class);
            $component->assertStatus(403);
        });
    }

    protected function getTenant()
    {
        return Tenant::first();
    }
}
