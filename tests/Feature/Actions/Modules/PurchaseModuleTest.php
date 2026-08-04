<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Tests\Feature\Actions\Modules;

use Nvade\Numerosis\Actions\Modules\PurchaseModule;
use Nvade\Numerosis\Enums\ModuleBillingMode;
use Nvade\Numerosis\Exceptions\Billing\BillingAddressRequired;
use Nvade\Numerosis\Exceptions\Billing\BillingAddressUnavailable;
use Nvade\Numerosis\Exceptions\Billing\ModuleAlreadyPurchased;
use Nvade\Numerosis\Exceptions\Billing\ModuleBillingNotAuthorized;
use Nvade\Numerosis\Exceptions\Billing\ModuleNotFound;
use Nvade\Numerosis\Exceptions\Billing\ModuleNotInstalled;
use Nvade\Numerosis\Exceptions\Billing\SubscriptionRequired;
use Nvade\Numerosis\Models\Central\CentralUser;
use Nvade\Numerosis\Models\Central\ModuleOffering;
use Nvade\Numerosis\Models\Central\PaymentPlan;
use Nvade\Numerosis\Models\Central\Subscription;
use Nvade\Numerosis\Models\Central\Tenant;
use Nvade\Numerosis\Models\Permission;
use Nvade\Numerosis\Models\Tenant\Module;
use Nvade\Numerosis\Models\Tenant\User as TenantUser;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Exceptions;
use Laravel\Cashier\Cashier;
use LogicException;
use Stripe\Exception\ApiConnectionException;
use Stripe\StripeClient;
use Nvade\Numerosis\Tests\TestCase;

class PurchaseModuleTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_throws_when_the_module_does_not_exist(): void
    {
        $tenant = Tenant::factory()->create();
        $owner = CentralUser::factory()->create();
        $tenant->users()->attach($owner, ['role' => 'owner']);

        $this->expectException(ModuleNotFound::class);

        PurchaseModule::run($tenant, $owner, 'does-not-exist');
    }

    /**
     * A central user who is not the owner holds no tenant roles at all, so
     * ModulePolicy refuses without ever asking Spatie for a permission that
     * only exists on the tenant guard.
     */
    public function test_it_throws_when_a_non_owner_central_user_purchases(): void
    {
        $tenant = Tenant::factory()->create();
        $owner = CentralUser::factory()->create();
        $tenant->users()->attach($owner, ['role' => 'owner']);

        $nonOwner = CentralUser::factory()->create();
        $tenant->users()->attach($nonOwner, ['role' => 'admin']);

        ModuleOffering::factory()->create(['slug' => 'alerts']);

        $tenant->run(function () use ($tenant, $nonOwner): void {
            $this->expectException(ModuleBillingNotAuthorized::class);

            PurchaseModule::run($tenant, $nonOwner, 'alerts');
        });
    }

    /**
     * A tenant user who is not the owner needs `purchase modules`; without it
     * the refusal is authorization, not a masked not-found.
     */
    public function test_it_throws_when_a_tenant_user_lacks_the_purchase_permission(): void
    {
        $tenant = Tenant::factory()->create();
        $owner = CentralUser::factory()->create();
        $tenant->users()->attach($owner, ['role' => 'owner']);

        ModuleOffering::factory()->create(['slug' => 'alerts']);

        $tenant->run(function () use ($tenant): void {
            $member = TenantUser::factory()->create();

            $this->expectException(ModuleBillingNotAuthorized::class);

            PurchaseModule::run($tenant, $member, 'alerts');
        });
    }

    /**
     * The permission, not membership, is what opens the path — a tenant user
     * who holds `purchase modules` gets past authorization and is stopped by
     * the next guard instead.
     */
    public function test_a_tenant_user_with_the_purchase_permission_passes_authorization(): void
    {
        $tenant = Tenant::factory()->create();
        $owner = CentralUser::factory()->create();
        $tenant->users()->attach($owner, ['role' => 'owner']);

        ModuleOffering::factory()->create(['slug' => 'alerts']);

        $tenant->run(function () use ($tenant): void {
            $member = TenantUser::factory()->create();
            $member->givePermissionTo(Permission::firstOrCreate([
                'name' => 'purchase modules',
                'guard_name' => 'tenant',
            ]));

            // Past authorization, refused by the billing-address guard —
            // the tenant has no Stripe customer in this test.
            $this->expectException(BillingAddressRequired::class);

            PurchaseModule::run($tenant, $member, 'alerts');
        });
    }

    public function test_it_throws_when_the_module_is_not_installed_on_this_node(): void
    {
        $tenant = Tenant::factory()->create();
        $owner = CentralUser::factory()->create();
        $tenant->users()->attach($owner, ['role' => 'owner']);

        // Catalogued (available, findBySlug succeeds) but no app-modules/*
        // package by this slug exists on the node — Modules::module() fails.
        ModuleOffering::factory()->create(['slug' => 'ghost-module']);

        $tenant->run(function () use ($tenant, $owner): void {
            $this->expectException(ModuleNotInstalled::class);

            PurchaseModule::run($tenant, $owner, 'ghost-module');
        });
    }

    /**
     * PurchaseModule authorizes against, and queries, the ambient tenant, so
     * being handed a different one is a programmer error rather than a
     * customer-facing refusal.
     */
    public function test_it_refuses_to_run_outside_the_tenant_it_is_purchasing_for(): void
    {
        $tenant = Tenant::factory()->create();
        $owner = CentralUser::factory()->create();
        $tenant->users()->attach($owner, ['role' => 'owner']);

        ModuleOffering::factory()->create(['slug' => 'alerts']);

        $this->expectException(LogicException::class);

        PurchaseModule::run($tenant, $owner, 'alerts');
    }

    public function test_it_throws_when_already_purchased(): void
    {
        $tenant = Tenant::factory()->create();
        $owner = CentralUser::factory()->create();
        $tenant->users()->attach($owner, ['role' => 'owner']);

        ModuleOffering::factory()->create(['slug' => 'alerts']);

        $tenant->run(function () use ($tenant, $owner): void {
            Module::create(['name' => 'alerts', 'purchased_at' => now(), 'enabled' => true]);

            $this->expectException(ModuleAlreadyPurchased::class);

            PurchaseModule::run($tenant, $owner, 'alerts');
        });
    }

    /**
     * Regression test for the missing lock: the already-purchased guard and
     * the Stripe charge are separate steps, so two concurrent purchases used
     * to both pass the guard and both bill the customer. Holding the exact
     * lock PurchaseModule now takes before calling it proves the guard is
     * serialized rather than merely present. block(5) genuinely waits, so
     * this test takes ~5s.
     */
    public function test_it_blocks_concurrent_purchases_of_the_same_module(): void
    {
        $tenant = Tenant::factory()->create();
        $owner = CentralUser::factory()->create();
        $tenant->users()->attach($owner, ['role' => 'owner']);

        ModuleOffering::factory()->create(['slug' => 'alerts']);

        $lock = Cache::lock("module-purchase:{$tenant->getTenantKey()}:alerts", 10);
        $this->assertTrue($lock->get());

        try {
            $this->expectException(LockTimeoutException::class);

            PurchaseModule::run($tenant, $owner, 'alerts');
        } finally {
            $lock->release();
        }
    }

    /**
     * Regression test for hasBillingAddress() letting a Stripe API failure
     * escape as an uncaught 500. Binds a fake StripeClient into the
     * container (Cashier::stripe() resolves it via app(StripeClient::class,
     * ...), and Container::resolve() always rebuilds when parameters are
     * passed, so a bind() closure — not an instance() — is what actually
     * gets hit) whose customers service always throws, without any real
     * network call.
     */
    public function test_it_converts_a_stripe_failure_while_checking_the_billing_address(): void
    {
        $tenant = Tenant::factory()->create();
        $owner = CentralUser::factory()->create();
        $tenant->users()->attach($owner, ['role' => 'owner']);

        ModuleOffering::factory()->create(['slug' => 'alerts']);

        // A real Stripe test-mode customer (as the other tests in this file
        // create), not a made-up id: setting stripe_id directly and saving
        // fires SyncTenantToStripe, which would hit real Stripe with a
        // nonexistent customer id and fail before the code under test runs.
        // The StripeClient bind below is what keeps the *retrieve* call
        // itself off the network.
        $tenant->createOrGetStripeCustomer();

        Exceptions::fake();

        $this->app->bind(StripeClient::class, fn (): StripeClient => new class extends StripeClient
        {
            public function __construct() {}

            public function __get(mixed $name): mixed
            {
                if ($name === 'customers') {
                    return new class
                    {
                        public function retrieve(string $id, mixed $params = null, mixed $opts = null): never
                        {
                            throw new ApiConnectionException('Simulated Stripe outage.');
                        }
                    };
                }

                return parent::__get($name);
            }
        });

        try {
            $tenant->run(function () use ($tenant, $owner): void {
                PurchaseModule::run($tenant, $owner, 'alerts');
            });

            $this->fail('Expected BillingAddressUnavailable to be thrown.');
        } catch (BillingAddressUnavailable) {
            // expected
        }

        Exceptions::assertReported(ApiConnectionException::class);
    }

    public function test_it_throws_when_the_customer_has_no_billing_address(): void
    {
        $tenant = Tenant::factory()->create();
        $owner = CentralUser::factory()->create();
        $tenant->users()->attach($owner, ['role' => 'owner']);

        ModuleOffering::factory()->create(['slug' => 'alerts']);

        $tenant->run(function () use ($tenant, $owner): void {
            $this->expectException(BillingAddressRequired::class);

            PurchaseModule::run($tenant, $owner, 'alerts');
        });
    }

    public function test_it_throws_when_a_recurring_module_has_no_active_subscription(): void
    {
        $tenant = Tenant::factory()->create();
        $owner = CentralUser::factory()->create();
        $tenant->users()->attach($owner, ['role' => 'owner']);

        ModuleOffering::factory()->create(['slug' => 'alerts', 'billing_mode' => ModuleBillingMode::Recurring]);

        $customer = $tenant->createOrGetStripeCustomer();
        Cashier::stripe()->customers->update($customer->id, [
            'address' => ['line1' => '123 Main St', 'city' => 'Amsterdam', 'postal_code' => '1000AA', 'country' => 'NL'],
        ]);

        $tenant->run(function () use ($tenant, $owner): void {
            $this->expectException(SubscriptionRequired::class);

            PurchaseModule::run($tenant, $owner, 'alerts');
        });
    }

    /**
     * Hits real Stripe test mode. invoicePrice() rejects a price whose type
     * is `recurring` for a one-off invoice item, so this module's price is
     * created fresh via the API rather than reusing one of the plan prices.
     */
    public function test_it_purchases_a_one_time_module(): void
    {
        $tenant = Tenant::factory()->create();
        $owner = CentralUser::factory()->create();
        $tenant->users()->attach($owner, ['role' => 'owner']);

        ModuleOffering::factory()->oneTime()->create([
            'slug' => 'alerts',
            'one_time_id' => $this->createOneTimePrice(),
        ]);

        $customer = $tenant->createOrGetStripeCustomer();
        Cashier::stripe()->customers->update($customer->id, [
            'address' => ['line1' => '123 Main St', 'city' => 'Amsterdam', 'postal_code' => '1000AA', 'country' => 'NL'],
        ]);

        $paymentMethod = Cashier::stripe()->paymentMethods->create([
            'type' => 'card',
            'card' => ['token' => 'tok_visa'],
        ]);
        Cashier::stripe()->paymentMethods->attach($paymentMethod->id, ['customer' => $customer->id]);
        $tenant->updateDefaultPaymentMethod($paymentMethod->id);

        $tenant->run(function () use ($tenant, $owner): void {
            PurchaseModule::run($tenant, $owner, 'alerts');

            $module = Module::where('name', 'alerts')->firstOrFail();

            $this->assertTrue($module->enabled);
            $this->assertNotNull($module->purchased_at);
            $this->assertNull($module->billing_cycle);
        });
    }

    /**
     * Hits real Stripe test mode end to end: creates a real subscription on
     * the tenant (the way CreateInlineSubscriptionTest does for a
     * CentralUser), then adds the module's price to it.
     */
    public function test_it_purchases_a_recurring_module_onto_an_active_subscription(): void
    {
        $priceId = Config::string('numerosis-billing.plans.0.monthly_id');

        if ($priceId === '') {
            $this->markTestSkipped('No Stripe test-mode price configured.');
        }

        $tenant = Tenant::factory()->create();
        $owner = CentralUser::factory()->create();
        $tenant->users()->attach($owner, ['role' => 'owner']);

        $plan = PaymentPlan::create([
            'name' => 'Starter',
            'slug' => 'starter-'.$tenant->id,
            'description' => 'Starter Plan',
            'monthly_id' => $priceId,
            'yearly_id' => $priceId,
            'monthly_price' => 1000,
            'yearly_price' => 10000,
            'available' => true,
            'trial_days' => 0,
        ]);

        // A distinct price from the base subscription's — adding the same
        // price id back onto the subscription is a Stripe "duplicate price"
        // error, not a purchase. Currency must match: a subscription's items
        // all have to share one currency.
        $baseCurrency = Cashier::stripe()->prices->retrieve($priceId)->currency;
        $modulePriceId = $this->createRecurringPrice($baseCurrency);

        ModuleOffering::factory()->create([
            'slug' => 'alerts',
            'billing_mode' => ModuleBillingMode::Recurring,
            'monthly_id' => $modulePriceId,
            'yearly_id' => $modulePriceId,
        ]);

        $customer = $tenant->createOrGetStripeCustomer();
        Cashier::stripe()->customers->update($customer->id, [
            'address' => ['line1' => '123 Main St', 'city' => 'Amsterdam', 'postal_code' => '1000AA', 'country' => 'NL'],
        ]);

        $paymentMethod = Cashier::stripe()->paymentMethods->create([
            'type' => 'card',
            'card' => ['token' => 'tok_visa'],
        ]);
        Cashier::stripe()->paymentMethods->attach($paymentMethod->id, ['customer' => $customer->id]);
        $tenant->updateDefaultPaymentMethod($paymentMethod->id);

        $stripeSubscription = $tenant->newSubscription('default', $priceId)->create($paymentMethod->id);

        Subscription::where('id', $stripeSubscription->id)->update(['payment_plan_id' => $plan->id]);

        $tenant->run(function () use ($tenant, $owner): void {
            PurchaseModule::run($tenant, $owner, 'alerts');

            $module = Module::where('name', 'alerts')->firstOrFail();

            $this->assertTrue($module->enabled);
            $this->assertNotNull($module->purchased_at);
            $this->assertNotNull($module->stripe_subscription_item_id);
        });
    }

    private function createOneTimePrice(): string
    {
        $product = Cashier::stripe()->products->create(['name' => 'Test Module (one-time)']);

        $price = Cashier::stripe()->prices->create([
            'product' => $product->id,
            'unit_amount' => 4900,
            'currency' => 'usd',
        ]);

        return $price->id;
    }

    private function createRecurringPrice(string $currency = 'usd'): string
    {
        $product = Cashier::stripe()->products->create(['name' => 'Test Module (recurring)']);

        $price = Cashier::stripe()->prices->create([
            'product' => $product->id,
            'unit_amount' => 500,
            'currency' => $currency,
            'recurring' => ['interval' => 'month'],
        ]);

        return $price->id;
    }
}
