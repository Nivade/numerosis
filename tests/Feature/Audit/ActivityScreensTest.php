<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Tests\Feature\Audit;

use App\Models\Central\CentralUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Nvade\Numerosis\Actions\Tenancy\SuspendTenant;
use Nvade\Numerosis\Database\Seeders\RoleAndPermissionSeeder;
use Nvade\Numerosis\Enums\Auth\SystemRole;
use Nvade\Numerosis\Features\Admin\StaffPanelFeature;
use Nvade\Numerosis\Features\Audit\ActivityLogFeature;
use Nvade\Numerosis\Features\FeatureRegistry;
use Nvade\Numerosis\Models\Central\CentralUser as BaseCentralUser;
use Nvade\Numerosis\Tests\Support\TestTenant;
use Nvade\Numerosis\Tests\TestCase;

class ActivityScreensTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        FeatureRegistry::forceForTesting([StaffPanelFeature::class, ActivityLogFeature::class]);

        parent::setUp();
    }

    public function test_the_staff_feed_lists_an_entry(): void
    {
        $tenant = TestTenant::provisioned(['provisioned_at' => now()]);

        SuspendTenant::run($tenant);

        Livewire::actingAs($this->admin())
            ->test('numerosis-pages::staff.activity')
            ->assertOk()
            ->assertSee('Tenant suspended');
    }

    public function test_the_staff_feed_filters_by_tenant(): void
    {
        $logged = TestTenant::provisioned(['provisioned_at' => now()]);
        $other = TestTenant::provisioned(['provisioned_at' => now()]);

        SuspendTenant::run($logged);

        Livewire::actingAs($this->admin())
            ->test('numerosis-pages::staff.activity', ['tenant' => $other->id])
            ->assertOk()
            ->assertDontSee('Tenant suspended');
    }

    private function admin(): BaseCentralUser
    {
        $this->seedPermissions();

        $admin = CentralUser::factory()->create();
        $admin->assignRole(SystemRole::Admin->value);

        return $admin;
    }

    private function seedPermissions(): void
    {
        resolve(RoleAndPermissionSeeder::class)->setContainer(app())->__invoke();
    }
}
