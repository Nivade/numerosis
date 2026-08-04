<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Tests\Feature\Actions\Modules;

use Nvade\Numerosis\Actions\Modules\ReconcileModuleSubscriptionItems;
use Nvade\Numerosis\Models\Central\Tenant;
use Nvade\Numerosis\Models\Tenant\Module;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Nvade\Numerosis\Tests\TestCase;

class ReconcileModuleSubscriptionItemsTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_module_whose_subscription_item_is_no_longer_present_is_disabled(): void
    {
        $tenant = Tenant::factory()->create();

        $tenant->run(function () {
            Module::create([
                'name' => 'tasks',
                'enabled' => true,
                'purchased_at' => now(),
                'stripe_subscription_item_id' => 'si_removed',
            ]);
            Module::create([
                'name' => 'notes',
                'enabled' => true,
                'purchased_at' => now(),
                'stripe_subscription_item_id' => 'si_still_there',
            ]);
        });

        ReconcileModuleSubscriptionItems::run($tenant, [
            'items' => ['data' => [['id' => 'si_still_there']]],
        ]);

        $tenant->run(function () {
            $this->assertFalse(Module::where('name', 'tasks')->value('enabled'));
            $this->assertTrue(Module::where('name', 'notes')->value('enabled'));
        });
    }

    public function test_a_one_time_module_with_no_subscription_item_is_left_alone(): void
    {
        $tenant = Tenant::factory()->create();

        $tenant->run(function () {
            Module::create([
                'name' => 'branding',
                'enabled' => true,
                'purchased_at' => now(),
                'stripe_subscription_item_id' => null,
            ]);
        });

        ReconcileModuleSubscriptionItems::run($tenant, [
            'items' => ['data' => [['id' => 'si_unrelated']]],
        ]);

        $tenant->run(function () {
            $this->assertTrue(Module::where('name', 'branding')->value('enabled'));
        });
    }

    public function test_a_payload_with_no_items_disables_nothing(): void
    {
        $tenant = Tenant::factory()->create();

        $tenant->run(function () {
            Module::create([
                'name' => 'tasks',
                'enabled' => true,
                'purchased_at' => now(),
                'stripe_subscription_item_id' => 'si_present',
            ]);
        });

        ReconcileModuleSubscriptionItems::run($tenant, []);

        $tenant->run(function () {
            $this->assertTrue(Module::where('name', 'tasks')->value('enabled'));
        });
    }
}
