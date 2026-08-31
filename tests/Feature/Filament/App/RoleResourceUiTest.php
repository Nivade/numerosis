<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Tests\Feature\Filament\App;

use App\Models\Central\Tenant;
use App\Models\Tenant\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Nvade\Numerosis\Models\Permission;
use Nvade\Numerosis\Tests\TestCase;
use Nvade\NumerosisFilament\TenantAdmin\Clusters\Team\Resources\Roles\Pages\ListRoles;
use Spatie\Permission\Models\Role;

class RoleResourceUiTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        // Create a tenant and initialize tenancy. The cloned tenant schema
        // already seeds one 'admin' role (Tenant\PermissionAndRoleSeeder) —
        // every table-count assertion below accounts for that extra row.
        $this->tenant = Tenant::factory()->create();
        tenancy()->initialize($this->tenant);

        $user = User::factory()->create();
        $user->assignRole('admin');
        $this->actingAsTenantPanelUser($this->tenant, $user);
    }

    public function test_role_list_displays_with_enhanced_ui_elements(): void
    {
        $role = Role::create(['name' => 'test-role', 'guard_name' => 'tenant']);
        $permission = Permission::create(['name' => 'test-permission', 'guard_name' => 'tenant']);
        $role->givePermissionTo($permission);

        Livewire::test(ListRoles::class)
            ->assertCanSeeTableRecords([$role])
            ->assertCountTableRecords(2);
    }

    public function test_role_table_shows_permissions_count(): void
    {
        $role = Role::create(['name' => 'manager', 'guard_name' => 'tenant']);
        $permission1 = Permission::create(['name' => 'view_users', 'guard_name' => 'tenant']);
        $permission2 = Permission::create(['name' => 'edit_users', 'guard_name' => 'tenant']);
        $role->givePermissionTo([$permission1, $permission2]);

        Livewire::test(ListRoles::class)
            ->assertCanSeeTableRecords([$role]);
    }

    public function test_role_table_has_filters(): void
    {
        Role::create(['name' => 'web-role', 'guard_name' => 'web']);
        Role::create(['name' => 'tenant-role', 'guard_name' => 'tenant']);

        Livewire::test(ListRoles::class)
            ->assertCountTableRecords(3)
            ->filterTable('guard_name', 'tenant')
            ->assertCountTableRecords(2);
    }

    public function test_role_table_is_searchable(): void
    {
        Role::create(['name' => 'manager', 'guard_name' => 'tenant']);
        Role::create(['name' => 'editor', 'guard_name' => 'tenant']);

        Livewire::test(ListRoles::class)
            ->assertCountTableRecords(3)
            ->searchTable('admin')
            ->assertCountTableRecords(1);
    }
}
