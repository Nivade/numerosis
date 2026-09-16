<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Tests\Feature\Staff;

use App\Models\Central\CentralUser;
use App\Models\Central\TenantProvision;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Nvade\Numerosis\Actions\Tenancy\CreateTenant;
use Nvade\Numerosis\Actions\Tenancy\MigrateTenantDatabase;
use Nvade\Numerosis\Database\Seeders\RoleAndPermissionSeeder;
use Nvade\Numerosis\Enums\Tenancy\StepOutcome;
use Nvade\Numerosis\Features\Admin\StaffPanelFeature;
use Nvade\Numerosis\Features\FeatureRegistry;
use Nvade\Numerosis\Models\Central\CentralUser as BaseCentralUser;
use Nvade\Numerosis\Tests\TestCase;

class StaffProvisionScreenTest extends TestCase
{
    use RefreshDatabase;

    private bool $permissionsSeeded = false;

    protected function setUp(): void
    {
        FeatureRegistry::forceForTesting([StaffPanelFeature::class]);

        parent::setUp();
    }

    public function test_the_timeline_carries_each_step_its_outcome_and_its_attempts(): void
    {
        $provision = TenantProvision::factory()->provisioning()->create(['slug' => 'timeline']);

        $provision->recordStep(CreateTenant::class, StepOutcome::Done, attempts: 1);
        $provision->recordStep(MigrateTenantDatabase::class, StepOutcome::Failed, 'disk full', 5);

        Livewire::actingAs($this->admin())
            ->test('numerosis-pages::staff.provision', ['slug' => 'timeline'])
            ->assertOk()
            ->assertSee('disk full')
            ->assertSee(StepOutcome::Failed->value);
    }

    /**
     * What a `Reserved` row looks like: claimed at checkout, never dispatched,
     * so every configured step is still pending.
     */
    public function test_it_renders_a_provision_with_no_step_records_at_all(): void
    {
        TenantProvision::factory()->create(['slug' => 'untouched', 'step_records' => []]);

        Livewire::actingAs($this->admin())
            ->test('numerosis-pages::staff.provision', ['slug' => 'untouched'])
            ->assertOk()
            ->assertSee('untouched');
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
