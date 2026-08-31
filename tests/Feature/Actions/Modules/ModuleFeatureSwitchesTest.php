<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Tests\Feature\Actions\Modules;

use App\Models\Central\CentralUser;
use App\Models\Central\Tenant;
use App\Models\Tenant\Module;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Nvade\Numerosis\Actions\Modules\CancelModule;
use Nvade\Numerosis\Actions\Modules\PurchaseModule;
use Nvade\Numerosis\Exceptions\Modules\ModulesDisabled;
use Nvade\Numerosis\Models\Central\ModuleOffering;
use Nvade\Numerosis\Support\Features;
use Nvade\Numerosis\Tests\TestCase;
use Nvade\NumerosisFilament\TenantAdmin\Pages\Modules\Marketplace;
use Nvade\NumerosisFilament\TenantAdmin\Pages\Modules\ModuleDetail;
use Nvade\NumerosisFilament\TenantAdmin\Resources\Modules\ModuleResource;

class ModuleFeatureSwitchesTest extends TestCase
{
    use RefreshDatabase;

    private function ownerOf(Tenant $tenant): CentralUser
    {
        $owner = CentralUser::factory()->create();
        $tenant->users()->attach($owner, ['role' => 'owner']);

        return $owner;
    }

    public function test_the_resource_is_inaccessible_when_modules_are_off(): void
    {
        Features::forceForTesting([]);

        $this->assertFalse(ModuleResource::canAccess());
    }

    /**
     * Marketplace.php lives inside TenantAdminPanelProvider's
     * ->discoverPages() scan (Pages/Modules/Marketplace.php), so an entry in
     * ->pages([...]) cannot gate it — discovery registers it regardless of
     * array membership. canAccess()/shouldRegisterNavigation() is the actual
     * gate; this asserts it directly rather than through Filament::getPanel(),
     * which would pass whether or not the override worked.
     */
    public function test_the_marketplace_page_is_inaccessible_when_modules_are_off(): void
    {
        Features::forceForTesting([]);

        $this->assertFalse(Marketplace::canAccess());
        $this->assertFalse(Marketplace::shouldRegisterNavigation());
    }

    /**
     * ModuleDetail has no navigation entry to hide and, until modules became
     * optional, no canAccess() override either — it relied on mount()'s 404,
     * which only fires *after* isInstalledOnThisNode() has already asked the
     * registry. That reads as a 500 rather than a 403 on a host without
     * internachi/modular installed.
     */
    public function test_the_module_detail_page_is_inaccessible_when_modules_are_off(): void
    {
        Features::forceForTesting([]);

        $this->assertFalse(ModuleDetail::canAccess());
    }

    /**
     * The `tenants:*-module` commands are registered only when the registry is
     * installed (NumerosisServiceProvider::moduleCommands()). Absence is
     * covered by tests/Feature/Features/ModuleRegistryAbsenceTest; this is the
     * present-and-registered half, so the gate cannot silently drop all three.
     */
    public function test_the_module_commands_are_registered_when_the_registry_is_installed(): void
    {
        $commands = array_keys(Artisan::all());

        $this->assertContains('tenants:migrate-module', $commands);
        $this->assertContains('tenants:rollback-module', $commands);
        $this->assertContains('tenants:seed-module', $commands);
    }

    public function test_purchasing_refuses_when_modules_are_off(): void
    {
        Features::forceForTesting([]);

        $tenant = Tenant::factory()->create();
        $owner = $this->ownerOf($tenant);

        ModuleOffering::factory()->oneTime()->create(['slug' => 'alerts']);

        $this->expectException(ModulesDisabled::class);

        PurchaseModule::run($tenant, $owner, 'alerts');
    }

    /**
     * CancelModule never gated on the modules feature — only on tenant
     * identity — so a subscriber who already reached the cancel action some
     * other way is never trapped paying for a module the UI has hidden.
     */
    public function test_cancelling_still_succeeds_when_modules_are_off(): void
    {
        Features::forceForTesting([]);

        $tenant = Tenant::factory()->create();
        $owner = $this->ownerOf($tenant);
        ModuleOffering::factory()->oneTime()->create(['slug' => 'alerts']);

        $tenant->run(function () use ($tenant, $owner): void {
            Module::create(['name' => 'alerts', 'purchased_at' => now(), 'enabled' => true]);

            CancelModule::run($tenant, $owner, 'alerts');

            $this->assertFalse(Module::where('name', 'alerts')->firstOrFail()->enabled);
        });
    }
}
