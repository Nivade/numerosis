<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Tests\Feature\Filament\TenantAdmin\Pages\Modules;

use App\Models\Central\CentralUser;
use App\Models\Central\Tenant;
use App\Models\Tenant\Module;
use App\Models\Tenant\User as TenantUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Nvade\Numerosis\Filament\TenantAdmin\Pages\Modules\Marketplace;
use Nvade\Numerosis\Models\Central\ModuleOffering;
use Nvade\Numerosis\Models\Permission;
use Nvade\Numerosis\Tests\TestCase;

class MarketplaceTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_lists_only_available_modules_installed_on_this_node(): void
    {
        // 'ghost-module' is catalogued but has no app-modules/* package on
        // this node, and must not appear.
        ModuleOffering::factory()->create(['slug' => 'alerts', 'name' => 'Alerts']);
        ModuleOffering::factory()->create(['slug' => 'ghost-module', 'name' => 'Ghost']);
        ModuleOffering::factory()->create(['slug' => 'legacy', 'name' => 'Legacy', 'available' => false]);

        $tenant = Tenant::factory()->create();
        $owner = CentralUser::factory()->create();
        $tenant->users()->attach($owner, ['role' => 'owner']);

        $tenant->run(function () use ($tenant, $owner): void {
            $this->actingAsTenantPanelUser($tenant, $owner);

            $modules = (new Marketplace)->getModules();
            $module = $modules->first();

            $this->assertCount(1, $modules);
            $this->assertNotNull($module);
            $this->assertSame('alerts', $module['slug']);
            $this->assertFalse($module['purchased']);
        });
    }

    public function test_a_purchased_module_is_marked_as_such(): void
    {
        ModuleOffering::factory()->create(['slug' => 'alerts', 'name' => 'Alerts']);

        $tenant = Tenant::factory()->create();
        $owner = CentralUser::factory()->create();
        $tenant->users()->attach($owner, ['role' => 'owner']);

        $tenant->run(function () use ($tenant, $owner): void {
            $this->actingAsTenantPanelUser($tenant, $owner);

            Module::create(['name' => 'alerts', 'purchased_at' => now(), 'enabled' => true]);

            $modules = (new Marketplace)->getModules();
            $module = $modules->first();

            $this->assertNotNull($module);
            $this->assertTrue($module['purchased']);
        });
    }

    /**
     * The purchase action is gated on ModulePolicy::purchase(), so an actor
     * with no tenant identity — here a CentralUser authenticated directly on
     * the tenant guard, the guard/identity mismatch
     * .claude/rules/auth-guards.md documents — never sees the button at all.
     * PurchasesModules::purchase() still notifies rather than returning
     * silently if that actor reaches it anyway, which is what the two
     * PurchaseModule authorization tests cover server-side.
     */
    public function test_the_purchase_action_is_hidden_from_an_actor_with_no_tenant_identity(): void
    {
        ModuleOffering::factory()->create(['slug' => 'alerts', 'name' => 'Alerts']);

        $tenant = Tenant::factory()->create();
        $owner = CentralUser::factory()->create();
        $tenant->users()->attach($owner, ['role' => 'owner']);

        $tenant->run(function () use ($tenant, $owner): void {
            $this->actingAsTenantPanelUser($tenant, $owner);

            Livewire::test(Marketplace::class)
                ->assertActionHidden('purchase');

            $this->assertDatabaseMissing('modules', ['name' => 'alerts']);
        });
    }

    /**
     * A tenant user who is not the owner and holds no `purchase modules`
     * permission must not be offered the button either — cancelling used to be
     * available to anyone who could see a module, and buying must not go the
     * same way.
     */
    public function test_the_purchase_action_is_hidden_from_a_tenant_user_without_the_permission(): void
    {
        ModuleOffering::factory()->create(['slug' => 'alerts', 'name' => 'Alerts']);

        $tenant = Tenant::factory()->create();
        $owner = CentralUser::factory()->create();
        $tenant->users()->attach($owner, ['role' => 'owner']);

        $tenant->run(function () use ($tenant): void {
            $member = TenantUser::factory()->create();
            $this->actingAsTenantPanelUser($tenant, $member);

            Livewire::test(Marketplace::class)
                ->assertActionHidden('purchase');
        });
    }

    public function test_the_purchase_action_is_visible_to_a_tenant_user_with_the_permission(): void
    {
        ModuleOffering::factory()->create(['slug' => 'alerts', 'name' => 'Alerts']);

        $tenant = Tenant::factory()->create();
        $owner = CentralUser::factory()->create();
        $tenant->users()->attach($owner, ['role' => 'owner']);

        $tenant->run(function () use ($tenant): void {
            $member = TenantUser::factory()->create();
            $member->givePermissionTo(Permission::firstOrCreate([
                'name' => 'purchase modules',
                'guard_name' => 'tenant',
            ]));

            $this->actingAsTenantPanelUser($tenant, $member);

            Livewire::test(Marketplace::class)
                ->assertActionVisible('purchase');
        });
    }
}
