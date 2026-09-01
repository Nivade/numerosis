<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Tests\Feature\Actions\Modules;

use App\Models\Central\Tenant;
use App\Models\Tenant\Module;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Nvade\Numerosis\Actions\Modules\RecordModulePurchase;
use Nvade\Numerosis\Enums\Billing\BillingCycle;
use Nvade\Numerosis\Models\Central\ModuleOffering;
use Nvade\Numerosis\Tests\TestCase;

class RecordModulePurchaseTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_creates_the_tenant_module_row(): void
    {
        $tenant = Tenant::factory()->create();
        $offer = ModuleOffering::factory()->create(['slug' => 'alerts']);

        $tenant->run(function () use ($offer): void {
            RecordModulePurchase::run($offer, 'si_123', BillingCycle::Monthly);

            $module = Module::where('name', 'alerts')->firstOrFail();

            $this->assertTrue($module->enabled);
            $this->assertNotNull($module->purchased_at);
            $this->assertSame('si_123', $module->stripe_subscription_item_id);
            $this->assertSame(BillingCycle::Monthly, $module->billing_cycle);
        });
    }

    public function test_it_is_idempotent(): void
    {
        $tenant = Tenant::factory()->create();
        $offer = ModuleOffering::factory()->create(['slug' => 'alerts']);

        $tenant->run(function () use ($offer): void {
            RecordModulePurchase::run($offer, 'si_123', BillingCycle::Monthly);
            RecordModulePurchase::run($offer, 'si_456', BillingCycle::Yearly);

            $this->assertSame(1, Module::where('name', 'alerts')->count());

            $module = Module::where('name', 'alerts')->firstOrFail();
            $this->assertSame('si_456', $module->stripe_subscription_item_id);
            $this->assertSame(BillingCycle::Yearly, $module->billing_cycle);
        });
    }
}
