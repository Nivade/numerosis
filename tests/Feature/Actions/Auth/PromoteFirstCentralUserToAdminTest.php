<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Tests\Feature\Actions\Auth;

use App\Models\Central\CentralUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Nvade\Numerosis\Models\Role;
use Nvade\Numerosis\Tests\TestCase;

class PromoteFirstCentralUserToAdminTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_first_central_user_created_is_promoted_to_admin(): void
    {
        Role::on('central')->create(['name' => 'admin', 'guard_name' => 'web']);

        $user = CentralUser::factory()->create();

        $this->assertTrue(CentralUser::findOrFail($user->id)->hasRole('admin'));
    }

    public function test_a_later_central_user_is_not_promoted(): void
    {
        Role::on('central')->create(['name' => 'admin', 'guard_name' => 'web']);

        CentralUser::factory()->create();
        $second = CentralUser::factory()->create();

        $this->assertFalse(CentralUser::findOrFail($second->id)->hasRole('admin'));
    }

    public function test_it_does_not_fail_when_the_admin_role_is_not_seeded_yet(): void
    {
        $user = CentralUser::factory()->create();

        $this->assertFalse(CentralUser::findOrFail($user->id)->hasRole('admin'));
    }
}
