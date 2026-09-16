<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Tests\Feature\Staff;

use App\Models\Central\CentralUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Nvade\Numerosis\Database\Seeders\RoleAndPermissionSeeder;
use Nvade\Numerosis\Features\Admin\StaffPanelFeature;
use Nvade\Numerosis\Features\FeatureRegistry;
use Nvade\Numerosis\Models\Central\CentralUser as BaseCentralUser;
use Nvade\Numerosis\Tests\TestCase;

class StaffPanelRoutesTest extends TestCase
{
    use RefreshDatabase;

    private bool $permissionsSeeded = false;

    /** @var list<string> */
    private const array ROUTES = [
        'staff.tenants',
        'staff.tenants.show',
        'staff.provisions',
        'staff.provisions.show',
        'staff.queue',
        'staff.migrations',
        'staff.subscriptions',
        'staff.users',
    ];

    protected function setUp(): void
    {
        // Routes are built at boot, so this has to land before the
        // application these tests make their requests against exists.
        FeatureRegistry::forceForTesting([StaffPanelFeature::class]);

        parent::setUp();
    }

    public function test_every_screen_registers_while_the_feature_is_on(): void
    {
        foreach (self::ROUTES as $name) {
            $this->assertTrue(Route::has($name), "Route [{$name}] is missing with the feature on.");
        }
    }

    public function test_a_central_user_without_tenant_permissions_is_forbidden_everywhere(): void
    {
        $this->actingAsCentralUser($this->userWithoutPermissions());

        $this->get(route('staff.tenants'))->assertForbidden();
        $this->get(route('staff.provisions'))->assertForbidden();
        $this->get(route('staff.subscriptions'))->assertForbidden();
        $this->get(route('staff.users'))->assertForbidden();
        $this->get(route('staff.tenants.show', 'whatever'))->assertForbidden();
        $this->get(route('staff.provisions.show', 'whatever'))->assertForbidden();
        $this->get(route('staff.queue'))->assertForbidden();
        $this->get(route('staff.migrations'))->assertForbidden();
    }

    public function test_a_guest_is_redirected_rather_than_forbidden(): void
    {
        $this->get(route('staff.tenants'))->assertRedirect();
    }

    public function test_an_admin_reaches_the_read_only_screens(): void
    {
        $this->actingAsCentralUser($this->admin());

        $this->get(route('staff.tenants'))->assertOk();
        $this->get(route('staff.provisions'))->assertOk();
        $this->get(route('staff.subscriptions'))->assertOk();
        $this->get(route('staff.users'))->assertOk();
        $this->get(route('staff.queue'))->assertOk();
        $this->get(route('staff.migrations'))->assertOk();
    }

    private function admin(): BaseCentralUser
    {
        $admin = $this->userWithoutPermissions();
        $admin->assignRole('admin');

        return $this->withConfirmedTwoFactor($admin);
    }

    /**
     * The decoy exists because `CentralUserObserver` promotes whichever user
     * is the only row in the central `users` table to `admin`.
     */
    private function userWithoutPermissions(): BaseCentralUser
    {
        $this->seedPermissionsOnce();

        CentralUser::factory()->create();

        return CentralUser::factory()->create();
    }

    /**
     * Once per test: every central role and permission row is written through
     * the `central` connection, which is autocommit, so re-seeding inside one
     * test leaves more rows for teardown to chase than it needs to.
     */
    private function seedPermissionsOnce(): void
    {
        if ($this->permissionsSeeded) {
            return;
        }

        $this->permissionsSeeded = true;

        (new RoleAndPermissionSeeder)->run();
    }
}
