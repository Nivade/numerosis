<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Tests\Feature\Staff;

use App\Models\Central\CentralUser;
use App\Models\Central\TenantMigrationRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Nvade\Numerosis\Database\Seeders\RoleAndPermissionSeeder;
use Nvade\Numerosis\Enums\Tenancy\MigrationRunStatus;
use Nvade\Numerosis\Features\Admin\StaffPanelFeature;
use Nvade\Numerosis\Features\FeatureRegistry;
use Nvade\Numerosis\Models\Central\CentralUser as BaseCentralUser;
use Nvade\Numerosis\Tests\Support\TestTenant;
use Nvade\Numerosis\Tests\TestCase;

class StaffMigrationsScreenTest extends TestCase
{
    use RefreshDatabase;

    private bool $permissionsSeeded = false;

    protected function setUp(): void
    {
        FeatureRegistry::forceForTesting([StaffPanelFeature::class]);

        parent::setUp();
    }

    public function test_it_lists_the_latest_run_with_its_failures_first(): void
    {
        TestTenant::provisioned(['id' => 'screenok']);
        TestTenant::provisioned(['id' => 'screenbad']);

        TenantMigrationRun::query()->create([
            'run_id' => 'run-screen',
            'tenant_id' => 'screenok',
            'status' => MigrationRunStatus::Succeeded,
            'migrations' => ['2026_09_17_000000_create_fleet_probes_table'],
        ]);

        TenantMigrationRun::query()->create([
            'run_id' => 'run-screen',
            'tenant_id' => 'screenbad',
            'status' => MigrationRunStatus::Failed,
            'error' => 'this tenant cannot be migrated',
        ]);

        Livewire::actingAs($this->admin())
            ->test('numerosis-pages::staff.migrations')
            ->assertOk()
            ->assertSee('this tenant cannot be migrated')
            ->assertSeeInOrder(['screenbad', 'screenok']);
    }

    public function test_it_renders_with_no_runs_at_all(): void
    {
        Livewire::actingAs($this->admin())
            ->test('numerosis-pages::staff.migrations')
            ->assertOk();
    }

    private function admin(): BaseCentralUser
    {
        $this->seedPermissionsOnce();

        CentralUser::factory()->create();

        $admin = CentralUser::factory()->withTwoFactor()->create();
        $admin->assignRole('admin');

        return $admin;
    }

    private function seedPermissionsOnce(): void
    {
        if ($this->permissionsSeeded) {
            return;
        }

        $this->permissionsSeeded = true;

        new RoleAndPermissionSeeder()->run();
    }
}
