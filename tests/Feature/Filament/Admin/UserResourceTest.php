<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Tests\Feature\Filament\Admin;

use App\Models\Central\CentralUser as User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Nvade\Numerosis\Filament\Admin\Resources\Users\Pages\EditUser;
use Nvade\Numerosis\Filament\Admin\Resources\Users\UserResource;
use Nvade\Numerosis\Models\Central\Role;
use Nvade\Numerosis\Tests\TestCase;

/**
 * UserResource::form() used Select::make('role_id')->relationship('role',
 * 'name') — neither 'role_id' nor a 'role' relation exist on CentralUser
 * (it uses Spatie's HasRoles trait, a many-to-many via model_has_roles, not
 * a single foreign key). Filament's relationship() validates the named
 * relation exists and throws LogicException otherwise, so every Edit User
 * visit crashed with "The relationship [role] does not exist on the model
 * [...CentralUser]." Had zero test coverage before this.
 */
class UserResourceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Gate::before(fn () => true);
    }

    public function test_edit_page_renders(): void
    {
        $viewer = User::factory()->create();
        $this->actingAs($viewer);

        $subject = User::factory()->create();

        $this->get(UserResource::getUrl('edit', ['record' => $subject]))
            ->assertSuccessful();
    }

    public function test_roles_field_reflects_assigned_roles(): void
    {
        $viewer = User::factory()->create();
        $this->actingAs($viewer);

        $subject = User::factory()->create();
        // Role::on($central) explicitly, not plain create(): Role is a
        // context-switching model whose ambient default connection leaves a
        // freshly-inserted row locked for the rest of the transaction, and
        // assignRole()'s model_has_roles insert (via CentralUser's own
        // 'central' connection) then blocks on it. Same trap documented in
        // .claude/rules/testing.md for RoleAndPermissionSeeder.
        $central = Config::string('tenancy.database.central_connection', 'central');
        $role = Role::on($central)->create(['name' => 'support', 'guard_name' => 'web']);
        $subject->assignRole($role);

        Livewire::test(EditUser::class, ['record' => $subject->getKey()])
            ->assertFormFieldExists('roles')
            ->assertFormSet(['roles' => [$role->id]]);
    }
}
