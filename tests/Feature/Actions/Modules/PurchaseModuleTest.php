<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Tests\Feature\Actions\Modules;

use App\Models\Central\CentralUser;
use App\Models\Central\Tenant;
use App\Models\Tenant\Module;
use App\Models\Tenant\User as TenantUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use InterNACHI\Modular\Support\Facades\Modules;
use InterNACHI\Modular\Support\ModuleConfig;
use InterNACHI\Modular\Support\ModuleRegistry;
use LogicException;
use Nvade\Numerosis\Actions\Modules\PurchaseModule;
use Nvade\Numerosis\Exceptions\Billing\BillingAddressRequired;
use Nvade\Numerosis\Exceptions\Billing\ModuleAlreadyPurchased;
use Nvade\Numerosis\Exceptions\Billing\ModuleBillingNotAuthorized;
use Nvade\Numerosis\Exceptions\Billing\ModuleNotFound;
use Nvade\Numerosis\Exceptions\Billing\ModuleNotInstalled;
use Nvade\Numerosis\Models\Central\CentralUser as CentralUserModel;
use Nvade\Numerosis\Models\Central\ModuleOffering;
use Nvade\Numerosis\Models\Central\Tenant as TenantModel;
use Nvade\Numerosis\Models\Permission;
use Nvade\Numerosis\Tests\TestCase;

/**
 * The guard clauses on `PurchaseModule`, which `CancelModuleTest`'s docblock
 * has called "the mirror of PurchaseModuleTest's guard" since it was written —
 * against a file that did not exist. Until this, `PurchaseModule` was reached
 * by exactly one test, incidentally, in `ModuleFeatureSwitchesTest`.
 *
 * Every case here stops **before** Stripe. That is not a compromise, it is
 * where the guards are: `purchase()` checks the offer, the tenant context,
 * authorization, installation, prior purchase and the billing address in that
 * order, and `hasBillingAddress()` returns false on a null `stripe_id` without
 * calling Stripe at all. Everything past that point — `SubscriptionRequired`,
 * `StripePriceNotConfigured`, the charge itself — needs a real customer with a
 * real address, and is covered the way `CancelModuleTest` covers its recurring
 * case: against Stripe test mode, skipped when no price is configured.
 *
 * The **order** of these checks is the load-bearing part and is why each test
 * sets up only as far as the clause it is aiming at. A test that satisfied
 * every earlier guard would pass just as well against an implementation that
 * checked them in any order, including one that authorized after charging.
 */
class PurchaseModuleTest extends TestCase
{
    use RefreshDatabase;

    /**
     * `ModulePolicy::purchase()` short-circuits to true for the tenant owner,
     * so every test that needs to get *past* authorization passes this actor,
     * and the one that tests authorization does not.
     *
     * Typed against the package's own model classes, not the host subclasses
     * the factories build at runtime: Larastan resolves `Model::factory()
     * ->create()` through the factory's generic, which names the package
     * class, so declaring the subclass here is a type error it is right about.
     * Same reason `PathModeTest`'s helper returns the package `Tenant`.
     */
    private function ownerOf(TenantModel $tenant): CentralUserModel
    {
        $owner = CentralUser::factory()->create();
        $tenant->users()->attach($owner, ['role' => 'owner']);

        return $owner;
    }

    /**
     * Fakes `internachi/modular`'s registry so a slug counts as installed on
     * this node without a module existing on disk.
     *
     * `ModuleRegistry` takes its loader as a constructor closure and
     * `ModuleConfig` has a plain public constructor, so a container instance
     * is enough. It must be `instance()`: the `Modules` facade resolves
     * `ModuleRegistry::class`, which modular's own provider registers as a
     * singleton.
     *
     * **The collection must be keyed by module name.** `Modules::module()` is
     * `modules()->get($name)`, a key lookup — a plain list passes
     * `modules()->count()` and every `filter()`/`map()` the marketplace does,
     * while `module('alerts')` quietly returns null and the caller reports
     * `ModuleNotInstalled` about a module the fake just declared installed.
     * `clearResolvedInstance()` is belt-and-braces next to that, but cheap:
     * a facade caches the object it resolved in its own static map.
     */
    private function installModules(string ...$slugs): void
    {
        $this->app?->instance(ModuleRegistry::class, new ModuleRegistry(
            '/tmp/numerosis-test-modules',
            fn (): Collection => collect($slugs)->mapWithKeys(
                fn (string $slug): array => [$slug => new ModuleConfig($slug, '/tmp/numerosis-test-modules/'.$slug)],
            ),
        ));

        Modules::clearResolvedInstance(ModuleRegistry::class);
    }

    public function test_it_throws_when_the_offer_does_not_exist(): void
    {
        $tenant = Tenant::factory()->create();
        $owner = $this->ownerOf($tenant);

        $tenant->run(function () use ($tenant, $owner): void {
            $this->expectException(ModuleNotFound::class);

            PurchaseModule::run($tenant, $owner, 'alerts');
        });
    }

    /**
     * Existence is not availability. `EloquentModuleCatalog::findBySlug()`
     * scopes on `available` precisely so a retired module cannot be bought by
     * a client that still remembers its slug — `findAnyBySlug()` is the
     * unscoped one, for admin screens.
     */
    public function test_it_throws_when_the_offer_exists_but_is_retired(): void
    {
        $tenant = Tenant::factory()->create();
        $owner = $this->ownerOf($tenant);

        ModuleOffering::factory()->oneTime()->create(['slug' => 'alerts', 'available' => false]);
        $this->installModules('alerts');

        $tenant->run(function () use ($tenant, $owner): void {
            $this->expectException(ModuleNotFound::class);

            PurchaseModule::run($tenant, $owner, 'alerts');
        });
    }

    /**
     * The mirror of `CancelModuleTest`'s own context guard. The offer lookup,
     * the installed-module lookup and `ModulePolicy`'s owner resolution all
     * read the *ambient* tenant, while the charge is made against the
     * argument — so running outside the tenant being bought for would bill one
     * tenant for another's module row.
     */
    public function test_it_refuses_to_run_outside_the_tenant_it_is_purchasing_for(): void
    {
        $tenant = Tenant::factory()->create();
        $owner = $this->ownerOf($tenant);

        ModuleOffering::factory()->oneTime()->create(['slug' => 'alerts', 'available' => true]);
        $this->installModules('alerts');

        $this->expectException(LogicException::class);

        PurchaseModule::run($tenant, $owner, 'alerts');
    }

    public function test_a_tenant_user_without_the_purchase_permission_is_refused(): void
    {
        $tenant = Tenant::factory()->create();
        $this->ownerOf($tenant);

        ModuleOffering::factory()->oneTime()->create(['slug' => 'alerts', 'available' => true]);
        $this->installModules('alerts');

        $tenant->run(function () use ($tenant): void {
            $member = TenantUser::factory()->create();

            $this->expectException(ModuleBillingNotAuthorized::class);

            PurchaseModule::run($tenant, $member, 'alerts');
        });
    }

    /**
     * Authorization is checked *before* installation, so a user who may not
     * buy anything learns nothing about which modules this node runs.
     */
    public function test_authorization_is_checked_before_installation(): void
    {
        $tenant = Tenant::factory()->create();
        $this->ownerOf($tenant);

        ModuleOffering::factory()->oneTime()->create(['slug' => 'alerts', 'available' => true]);
        $this->installModules();

        $tenant->run(function () use ($tenant): void {
            $member = TenantUser::factory()->create();

            $this->expectException(ModuleBillingNotAuthorized::class);

            PurchaseModule::run($tenant, $member, 'alerts');
        });
    }

    /**
     * A module can be *offered* centrally and absent from this node's code —
     * the same intersection `Marketplace::getModules()` renders. Buying one
     * would charge for something that can never run.
     */
    public function test_it_throws_when_the_module_is_not_installed_on_this_node(): void
    {
        $tenant = Tenant::factory()->create();
        $owner = $this->ownerOf($tenant);

        ModuleOffering::factory()->oneTime()->create(['slug' => 'alerts', 'available' => true]);
        $this->installModules();

        $tenant->run(function () use ($tenant, $owner): void {
            $this->expectException(ModuleNotInstalled::class);

            PurchaseModule::run($tenant, $owner, 'alerts');
        });
    }

    public function test_it_throws_when_the_module_was_already_purchased(): void
    {
        $tenant = Tenant::factory()->create();
        $owner = $this->ownerOf($tenant);

        ModuleOffering::factory()->oneTime()->create(['slug' => 'alerts', 'available' => true]);
        $this->installModules('alerts');

        $tenant->run(function () use ($tenant, $owner): void {
            Module::create(['name' => 'alerts', 'purchased_at' => now(), 'enabled' => true]);

            $this->expectException(ModuleAlreadyPurchased::class);

            PurchaseModule::run($tenant, $owner, 'alerts');
        });
    }

    /**
     * A cancelled module keeps its `purchased_at` — `CancelModule` only
     * disables it — so "already purchased" is deliberately about that column
     * and not about `enabled`. Re-buying a module you already paid for stays
     * refused; re-enabling it is a different operation.
     */
    public function test_a_disabled_but_paid_module_still_counts_as_purchased(): void
    {
        $tenant = Tenant::factory()->create();
        $owner = $this->ownerOf($tenant);

        ModuleOffering::factory()->oneTime()->create(['slug' => 'alerts', 'available' => true]);
        $this->installModules('alerts');

        $tenant->run(function () use ($tenant, $owner): void {
            Module::create(['name' => 'alerts', 'purchased_at' => now(), 'enabled' => false]);

            $this->expectException(ModuleAlreadyPurchased::class);

            PurchaseModule::run($tenant, $owner, 'alerts');
        });
    }

    /**
     * A module row with no `purchased_at` is not a purchase — it is the row
     * the module system creates for an installed-but-unbought module — so it
     * must not block one. This is the negative control for the two tests
     * above: without it they pass equally against an implementation that
     * refused on the row's mere existence.
     *
     * Reaching `BillingAddressRequired` is the proof that the
     * already-purchased guard was passed, and it is the next check in order.
     * `hasBillingAddress()` returns false on a null `stripe_id` without
     * calling Stripe, which is what keeps this test offline.
     */
    public function test_an_unpurchased_module_row_does_not_block_a_purchase(): void
    {
        $tenant = Tenant::factory()->create(['stripe_id' => null]);
        $owner = $this->ownerOf($tenant);

        ModuleOffering::factory()->oneTime()->create(['slug' => 'alerts', 'available' => true]);
        $this->installModules('alerts');

        $tenant->run(function () use ($tenant, $owner): void {
            Module::create(['name' => 'alerts', 'purchased_at' => null, 'enabled' => false]);

            $this->expectException(BillingAddressRequired::class);

            PurchaseModule::run($tenant, $owner, 'alerts');
        });
    }

    public function test_it_requires_a_billing_address_before_charging(): void
    {
        $tenant = Tenant::factory()->create(['stripe_id' => null]);
        $owner = $this->ownerOf($tenant);

        ModuleOffering::factory()->oneTime()->create(['slug' => 'alerts', 'available' => true]);
        $this->installModules('alerts');

        $tenant->run(function () use ($tenant, $owner): void {
            $this->expectException(BillingAddressRequired::class);

            PurchaseModule::run($tenant, $owner, 'alerts');
        });
    }

    /**
     * The permission path, rather than the owner short-circuit every other
     * test here rides. `BillingAddressRequired` again marks "got past
     * authorization", which is the whole claim.
     */
    public function test_a_tenant_user_with_the_purchase_permission_may_proceed(): void
    {
        $tenant = Tenant::factory()->create(['stripe_id' => null]);
        $this->ownerOf($tenant);

        ModuleOffering::factory()->oneTime()->create(['slug' => 'alerts', 'available' => true]);
        $this->installModules('alerts');

        $tenant->run(function () use ($tenant): void {
            $member = TenantUser::factory()->create();
            $member->givePermissionTo(Permission::firstOrCreate([
                'name' => 'purchase modules',
                'guard_name' => 'tenant',
            ]));

            $this->expectException(BillingAddressRequired::class);

            PurchaseModule::run($tenant, $member, 'alerts');
        });
    }
}
