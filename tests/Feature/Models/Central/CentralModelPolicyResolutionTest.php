<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Tests\Feature\Models\Central;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Nvade\Numerosis\Database\Seeders\RoleAndPermissionSeeder;
use Nvade\Numerosis\Models\Central\CentralUser;
use Nvade\Numerosis\Models\Central\Invitation;
use Nvade\Numerosis\Models\Central\PaymentPlan;
use Nvade\Numerosis\Models\Central\PlanFeature;
use Nvade\Numerosis\Models\Central\SocialAccount;
use Nvade\Numerosis\Models\Central\Subscription;
use Nvade\Numerosis\Models\Central\Tenant;
use Nvade\Numerosis\Models\Permission;
use Nvade\Numerosis\Models\Role;
use Nvade\Numerosis\Models\Tenant\User as TenantUser;
use Nvade\Numerosis\Numerosis;
use Nvade\Numerosis\Policies\InvitationPolicy;
use Nvade\Numerosis\Policies\PaymentPlanPolicy;
use Nvade\Numerosis\Policies\PermissionPolicy;
use Nvade\Numerosis\Policies\PlanFeaturePolicy;
use Nvade\Numerosis\Policies\RolePolicy;
use Nvade\Numerosis\Policies\SocialAccountPolicy;
use Nvade\Numerosis\Policies\SubscriptionPolicy;
use Nvade\Numerosis\Policies\TenantPolicy;
use Nvade\Numerosis\Policies\UserPolicy;
use Nvade\Numerosis\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * PHP attributes are not inherited by subclasses, so #[UsePolicy] on
 * Nvade\Numerosis\Models\User/Role/Permission does not cover the Nvade\Numerosis\Models\Central\*
 * subclasses the Admin panel's resources actually point at. With no policy
 * resolvable, `Gate::allows()` has nothing to deny with and every caller that
 * treats "no policy" as "not forbidden" allows. These assertions would fail against
 * the pre-fix classes, which carried no #[UsePolicy] of their own.
 *
 * CentralUser is the documented exception, not a fourth instance of the same
 * bug: #[UsePolicy(UserPolicy::class)] lives only on Tenant\User, and
 * .ai/rules/auth-guards.md records this as a deliberate, unresolved gap
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

    /**
     * The assertions in this file name the *package's* classes, and every
     * resources do not: they point at `Numerosis::model(...)`, which on any
     * host using the documented model-override seam is a **subclass**. Since
     * PHP attributes are not inherited, every one of those subclasses resolved
     * no policy at all — and a non-strict authorization layer allows when
     * no policy resolves, so a central user with zero roles could reach the
     * Tenants, PaymentPlans and Subscriptions resources and their write
     * actions. Found 2026-09-01 from a browser assertion that would not fail;
     * this test is one class above where the bug lived.
     *
     * `NumerosisServiceProvider::registerPolicies()` is the fix — an explicit
     * `Gate::policy()` per pairing, registered against the package class so
     * `getPolicyFor()`'s `is_subclass_of` sweep catches every subclass while a
     * host's own `App\Policies\*` convention still wins ahead of it.
     *
     * Written as a loop over `Numerosis::model()` rather than one method per
     * model on purpose: the defect was not that a particular pairing was
     * missing, it was that *the resolved class was never the one asserted on*.
     * A new model with a policy is covered here by construction.
     *
     * @return array<string, array{class-string<\Illuminate\Database\Eloquent\Model>, class-string}>
     */
    public static function policyResolutionProvider(): array
    {
        return [
            'tenant' => [Tenant::class, TenantPolicy::class],
            'payment plan' => [PaymentPlan::class, PaymentPlanPolicy::class],
            'subscription' => [Subscription::class, SubscriptionPolicy::class],
            'plan feature' => [PlanFeature::class, PlanFeaturePolicy::class],
            'role' => [Role::class, RolePolicy::class],
            'permission' => [Permission::class, PermissionPolicy::class],
            'tenant user' => [TenantUser::class, UserPolicy::class],
            'invitation' => [Invitation::class, InvitationPolicy::class],
            'social account' => [SocialAccount::class, SocialAccountPolicy::class],
        ];
    }

    /**
     * @param  class-string<\Illuminate\Database\Eloquent\Model>  $model
     * @param  class-string  $policy
     */
    #[DataProvider('policyResolutionProvider')]
    public function test_the_resolved_model_class_resolves_its_policy(string $model, string $policy): void
    {
        $resolved = Numerosis::model($model);

        $this->assertInstanceOf(
            $policy,
            Gate::getPolicyFor($resolved),
            "[{$resolved}] resolves no policy. A non-strict authorization layer allows when no policy resolves, so this is an open resource, not a hidden one.",
        );
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
     * Tenant/PaymentPlan/Subscription/Feature carried no
     * #[UsePolicy] at all until this test was written, so any authenticated
     * central user could reach every write action on every one of them
     * through an admin screen — a non-strict authorization layer defaults
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
        $this->assertInstanceOf(PlanFeaturePolicy::class, Gate::getPolicyFor(PlanFeature::class));
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
        $this->assertFalse($user->can('viewAny', PlanFeature::class));
    }

    public function test_a_seeded_admin_can_manage_tenants_plans_subscriptions_and_features(): void
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
        $this->assertTrue($user->can('viewAny', PlanFeature::class));
    }
}
