<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Tests\Feature\Actions\Modules;

use Nvade\Numerosis\Actions\Modules\CancelModule;
use Nvade\Numerosis\Actions\Modules\PurchaseModule;
use Nvade\Numerosis\Exceptions\Modules\ModulesDisabled;
use Nvade\Numerosis\Filament\TenantAdmin\Pages\Modules\Marketplace;
use Nvade\Numerosis\Filament\TenantAdmin\Resources\Modules\ModuleResource;
use Nvade\Numerosis\Models\Central\CentralUser;
use Nvade\Numerosis\Models\Central\ModuleOffering;
use Nvade\Numerosis\Models\Central\Tenant;
use Nvade\Numerosis\Models\Tenant\Module;
use Nvade\Numerosis\Support\Features;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Nvade\Numerosis\Tests\TestCase;

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
