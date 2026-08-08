<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Tests\Feature\Models\Central;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Nvade\Numerosis\Database\Seeders\RoleAndPermissionSeeder;
use Nvade\Numerosis\Models\Central\CentralUser;
use Nvade\Numerosis\Models\Central\Feature;
use Nvade\Numerosis\Models\Central\ModuleOffering;
use Nvade\Numerosis\Models\Central\PaymentPlan;
use Nvade\Numerosis\Models\Central\Permission;
use Nvade\Numerosis\Models\Central\Role;
use Nvade\Numerosis\Models\Central\Subscription;
use Nvade\Numerosis\Models\Central\Tenant;
use Nvade\Numerosis\Policies\FeaturePolicy;
use Nvade\Numerosis\Policies\ModuleOfferingPolicy;
use Nvade\Numerosis\Policies\PaymentPlanPolicy;
use Nvade\Numerosis\Policies\PermissionPolicy;
use Nvade\Numerosis\Policies\RolePolicy;
use Nvade\Numerosis\Policies\SubscriptionPolicy;
use Nvade\Numerosis\Policies\TenantPolicy;
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

    /**
     * Tenant/PaymentPlan/Subscription/Feature/ModuleOffering carried no
     * #[UsePolicy] at all until this test was written, so any authenticated
     * central user could reach every write action on every one of them
     * through the admin panel — Filament's non-strict authorization defaults
     * to allow when no policy resolves, same mechanism the docblock above
     * describes for Role/Permission before their fix.
     */
    public function test_central_tenant_resolves_the_tenant_policy(): void
    {
        $this->assertInstanceOf(TenantPolicy::class, Gate::getPolicyFor(Tenant::class));
    }

    public function test_central_payment_plan_resolves_the_payment_plan_policy(): void
    {
        $this->assertInstanceOf(PaymentPlanPolicy::class, Gate::getPolicyFor(PaymentPlan::class));
    }

    public function test_central_subscription_resolves_the_subscription_policy(): void
    {
        $this->assertInstanceOf(SubscriptionPolicy::class, Gate::getPolicyFor(Subscription::class));
    }

    public function test_central_feature_resolves_the_feature_policy(): void
    {
        $this->assertInstanceOf(FeaturePolicy::class, Gate::getPolicyFor(Feature::class));
    }

    public function test_central_module_offering_resolves_the_module_offering_policy(): void
    {
        $this->assertInstanceOf(ModuleOfferingPolicy::class, Gate::getPolicyFor(ModuleOffering::class));
    }

    public function test_a_central_user_without_permissions_cannot_manage_users_roles_or_permissions(): void
    {
        // Not $this->seed(): stancl's `Commands\Seed` extends Laravel's
        // `SeedCommand` without overriding its inherited `$signature`
        // (`'db:seed {--class=...}'`), and `Illuminate\Console\Command::
        // __construct()` builds the command's name from `$signature` when
        // set, ignoring `protected $name = 'tenants:seed'` entirely — so
        // under test (where Laravel's own `db:seed` never registers because
        // `SeedServiceProvider` guards on `runningInConsole()`), `db:seed`
        // resolves to stancl's command instead, which requires `--tenants`.
        // Calling the seeder directly sidesteps the console layer.
        (new RoleAndPermissionSeeder)->run();

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
        $this->assertFalse($user->can('viewAny', Tenant::class));
        $this->assertFalse($user->can('viewAny', PaymentPlan::class));
        $this->assertFalse($user->can('viewAny', Subscription::class));
        $this->assertFalse($user->can('viewAny', Feature::class));
        $this->assertFalse($user->can('viewAny', ModuleOffering::class));
    }

    public function test_a_seeded_admin_can_manage_tenants_plans_subscriptions_features_and_modules(): void
    {
        (new RoleAndPermissionSeeder)->run();

        // Decoy first, same reasoning as above: the second user is the one
        // this test actually means to exercise, given the 'admin' role.
        CentralUser::factory()->create();

        $user = CentralUser::factory()->create();
        $user->assignRole('admin');

        $this->assertTrue($user->can('viewAny', Tenant::class));
        $this->assertTrue($user->can('viewAny', PaymentPlan::class));
        $this->assertTrue($user->can('viewAny', Subscription::class));
        $this->assertTrue($user->can('viewAny', Feature::class));
        $this->assertTrue($user->can('viewAny', ModuleOffering::class));
    }
}
