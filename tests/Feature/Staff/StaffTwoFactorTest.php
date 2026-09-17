<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Tests\Feature\Staff;

use App\Models\Central\CentralUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;
use Nvade\Numerosis\Actions\Auth\ClearTwoFactorAuthentication;
use Nvade\Numerosis\Database\Seeders\RoleAndPermissionSeeder;
use Nvade\Numerosis\Features\Admin\StaffPanelFeature;
use Nvade\Numerosis\Features\FeatureRegistry;
use Nvade\Numerosis\Models\Central\CentralUser as BaseCentralUser;
use Nvade\Numerosis\Models\Role;
use Nvade\Numerosis\Tests\TestCase;

class StaffTwoFactorTest extends TestCase
{
    use RefreshDatabase;

    private bool $permissionsSeeded = false;

    protected function setUp(): void
    {
        // Routes are built at boot, so this has to land before the application
        // these tests make their requests against exists.
        FeatureRegistry::forceForTesting([StaffPanelFeature::class]);

        parent::setUp();
    }

    /**
     * Every permission and still no way in: the staff screens suspend tenants
     * and mint impersonation links, so the factor is not negotiable there.
     */
    public function test_an_admin_without_a_confirmed_factor_cannot_reach_the_staff_screens(): void
    {
        $this->actingAsCentralUser($this->admin());

        $this->get(route('staff.tenants'))->assertRedirect(route('settings.two-factor'));
        $this->get(route('staff.users'))->assertRedirect(route('settings.two-factor'));
    }

    /**
     * `EnsureStaffTwoFactor` used to hardcode `route('settings.two-factor')`.
     * Renaming the config key and pointing it at an unrelated route proves the
     * redirect target is read from `numerosis.routes.names`, not the literal.
     */
    public function test_the_redirect_target_is_read_from_config(): void
    {
        Route::get('/renamed-two-factor-screen', fn (): string => 'ok')->name('renamed.two-factor');
        Route::getRoutes()->refreshNameLookups();

        Config::set('numerosis.routes.names.two_factor_settings', 'renamed.two-factor');

        $this->actingAsCentralUser($this->admin());

        $this->get(route('staff.tenants'))->assertRedirect(route('renamed.two-factor'));
    }

    public function test_a_visitor_without_staff_permissions_still_gets_a_403(): void
    {
        $this->actingAsCentralUser($this->plainUser());

        $this->get(route('staff.tenants'))->assertForbidden();
    }

    public function test_an_admin_with_a_confirmed_factor_reaches_them(): void
    {
        $this->actingAsCentralUser($this->withConfirmedTwoFactor($this->admin()));

        $this->get(route('staff.tenants'))->assertOk();
    }

    public function test_clearing_a_users_factor_writes_an_activity_log_entry_naming_both(): void
    {
        $staff = $this->withConfirmedTwoFactor($this->admin());
        $target = $this->withConfirmedTwoFactor($this->plainUser());

        $this->actingAsCentralUser($staff);

        Livewire::test('numerosis-pages::staff.users')
            ->call('clearTwoFactor', $target->getKey());

        $this->assertFalse($target->fresh()?->hasEnabledTwoFactorAuthentication());

        $this->assertDatabaseHas('activity_log', [
            'description' => "Two-factor authentication for {$target->global_id} cleared by staff {$staff->global_id}",
            'causer_id' => $staff->getKey(),
        ]);
    }

    /** Withheld from the CRUD actions, so reading the list is not enough. */
    public function test_a_staff_user_without_the_ability_cannot_clear_a_factor(): void
    {
        $staff = $this->withConfirmedTwoFactor($this->admin());

        // The ability arrives through the `admin` role, so revoking it from the
        // user directly would leave the check passing.
        Role::findByName('admin', 'web')->revokePermissionTo(ClearTwoFactorAuthentication::ability());

        $target = $this->withConfirmedTwoFactor($this->plainUser());

        $this->actingAsCentralUser($staff);

        Livewire::test('numerosis-pages::staff.users')
            ->call('clearTwoFactor', $target->getKey())
            ->assertForbidden();

        $this->assertTrue($target->fresh()?->hasEnabledTwoFactorAuthentication());
    }

    private function admin(): BaseCentralUser
    {
        $admin = $this->plainUser();
        $admin->assignRole('admin');

        return $admin;
    }

    /** The decoy covers `CentralUserObserver`'s promotion of the first user. */
    private function plainUser(): BaseCentralUser
    {
        $this->seedPermissionsOnce();

        CentralUser::factory()->create();

        /** @var BaseCentralUser $user */
        $user = CentralUser::factory()->create();

        return $user;
    }

    private function seedPermissionsOnce(): void
    {
        if ($this->permissionsSeeded) {
            return;
        }

        $this->permissionsSeeded = true;

        (new RoleAndPermissionSeeder)->run();
    }
}
