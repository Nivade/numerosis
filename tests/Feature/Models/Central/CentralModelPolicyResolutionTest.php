<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Tests\Feature\Models\Central;

use Nvade\Numerosis\Models\Central\CentralUser;
use Nvade\Numerosis\Models\Central\Permission;
use Nvade\Numerosis\Models\Central\Role;
use Nvade\Numerosis\Policies\PermissionPolicy;
use Nvade\Numerosis\Policies\RolePolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Nvade\Numerosis\Tests\TestCase;

/**
 * PHP attributes are not inherited by subclasses, so #[UsePolicy] on
 * Nvade\Numerosis\Models\User/Role/Permission does not cover the Nvade\Numerosis\Models\Central\*
 * subclasses the Admin panel's resources actually point at. With no policy
 * resolvable, Filament's non-strict authorization defaults to allow — see
 * Filament\get_authorization_response(). These assertions would fail against
 * the pre-fix classes, which carried no #[UsePolicy] of their own.
 *
 * CentralUser is the documented exception, not a fourth instance of the same
 * bug: #[UsePolicy(UserPolicy::class)] lives only on Tenant\User, and
 * .claude/rules/auth-guards.md records this as a deliberate, unresolved gap
 * — moving it onto the shared Nvade\Numerosis\Models\User base would change
 * authorization behaviour for every central-panel check (and needs
 * UserPolicy::viewAny() to exist first, or the missing-method throws instead
 * of denying). Asserting null here, not UserPolicy::class, until that
 * decision is made.
 */
class CentralModelPolicyResolutionTest extends TestCase
{
    use RefreshDatabase;

    public function test_central_user_resolves_no_policy(): void
    {
        $this->assertNull(Gate::getPolicyFor(CentralUser::class));
    }

    public function test_central_role_resolves_the_role_policy(): void
    {
        $this->assertInstanceOf(RolePolicy::class, Gate::getPolicyFor(Role::class));
    }

    public function test_central_permission_resolves_the_permission_policy(): void
    {
        $this->assertInstanceOf(PermissionPolicy::class, Gate::getPolicyFor(Permission::class));
    }

    public function test_a_central_user_without_permissions_cannot_manage_users_roles_or_permissions(): void
    {
        $this->seed(\Nvade\Numerosis\Database\Seeders\RoleAndPermissionSeeder::class);

        // A decoy user first: Nvade\Numerosis\Observers\CentralUserObserver::created()
        // runs PromoteFirstCentralUserToAdmin on every new CentralUser, which
        // auto-assigns the (now fully-permissioned) 'admin' role to whichever
        // user is the *only* row in the central `users` table — exactly the
        // "without permissions" user this test means to build, if it were
        // created alone. See PromoteFirstCentralUserToAdminTest for the same
        // decoy pattern.
        CentralUser::factory()->create();

        $user = CentralUser::factory()->create();

        $this->assertFalse($user->can('viewAny', CentralUser::class));
        $this->assertFalse($user->can('viewAny', Role::class));
        $this->assertFalse($user->can('viewAny', Permission::class));
    }
}
