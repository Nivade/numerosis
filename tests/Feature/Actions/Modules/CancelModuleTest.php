<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Tests\Feature\Actions\Modules;

use Nvade\Numerosis\Actions\Modules\CancelModule;
use Nvade\Numerosis\Enums\ModuleBillingMode;
use Nvade\Numerosis\Exceptions\Billing\ModuleBillingNotAuthorized;
use Nvade\Numerosis\Exceptions\Billing\ModuleNotFound;
use Nvade\Numerosis\Models\Central\CentralUser;
use Nvade\Numerosis\Models\Central\ModuleOffering;
use Nvade\Numerosis\Models\Central\PaymentPlan;
use Nvade\Numerosis\Models\Central\Subscription;
use Nvade\Numerosis\Models\Central\Tenant;
use Nvade\Numerosis\Models\Permission;
use Nvade\Numerosis\Models\Tenant\Module;
use Nvade\Numerosis\Models\Tenant\User as TenantUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Laravel\Cashier\Cashier;
use LogicException;
use Nvade\Numerosis\Tests\TestCase;

class CancelModuleTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The owner is allowed to cancel with no permission row at all, which is
     * why every test here attaches one and passes it as the actor.
     */
    private function ownerOf(Tenant $tenant): CentralUser
    {
        $owner = CentralUser::factory()->create();
        $tenant->users()->attach($owner, ['role' => 'owner']);

        return $owner;
    }

    /**
     * The mirror of PurchaseModuleTest's guard. Module lookup and
     * ModulePolicy's owner resolution both read the ambient tenant, while
     * $tenant->latestSubscription() reads the argument — a mismatch would
     * remove a price from a different tenant's subscription than the one
     * whose module row was checked.
     */
    public function test_it_refuses_to_run_outside_the_tenant_it_is_cancelling_for(): void
    {
        $tenant = Tenant::factory()->create();
        $owner = $this->ownerOf($tenant);

        ModuleOffering::factory()->oneTime()->create(['slug' => 'alerts']);

        $this->expectException(LogicException::class);

        CancelModule::run($tenant, $owner, 'alerts');
    }

    public function test_it_throws_when_the_module_was_never_purchased(): void
    {
        $tenant = Tenant::factory()->create();
        $owner = $this->ownerOf($tenant);

        $tenant->run(function () use ($tenant, $owner): void {
            $this->expectException(ModuleNotFound::class);

            CancelModule::run($tenant, $owner, 'alerts');
        });
    }

    public function test_cancelling_a_one_time_module_only_disables_it(): void
    {
        $tenant = Tenant::factory()->create();
        $owner = $this->ownerOf($tenant);
        ModuleOffering::factory()->oneTime()->create(['slug' => 'alerts']);

        $tenant->run(function () use ($tenant, $owner): void {
            Module::create(['name' => 'alerts', 'purchased_at' => now(), 'enabled' => true]);

            CancelModule::run($tenant, $owner, 'alerts');

            $module = Module::where('name', 'alerts')->firstOrFail();
            $this->assertFalse($module->enabled);
            $this->assertNotNull($module->purchased_at, 'Cancelling must never destroy the tenant row.');
        });
    }

    /**
     * Before this, cancelling was gated only by whoever could see the modules
     * table — a weaker rule than purchasing, so a user who could not buy a
     * module could still stop paying for one.
     */
    public function test_a_tenant_user_without_the_cancel_permission_is_refused(): void
    {
        $tenant = Tenant::factory()->create();
        $this->ownerOf($tenant);
        ModuleOffering::factory()->oneTime()->create(['slug' => 'alerts']);

        $tenant->run(function () use ($tenant): void {
            Module::create(['name' => 'alerts', 'purchased_at' => now(), 'enabled' => true]);
            $member = TenantUser::factory()->create();

            $this->expectException(ModuleBillingNotAuthorized::class);

            CancelModule::run($tenant, $member, 'alerts');
        });
    }

    public function test_a_tenant_user_with_the_cancel_permission_may_cancel(): void
    {
        $tenant = Tenant::factory()->create();
        $this->ownerOf($tenant);
        ModuleOffering::factory()->oneTime()->create(['slug' => 'alerts']);

        $tenant->run(function () use ($tenant): void {
            Module::create(['name' => 'alerts', 'purchased_at' => now(), 'enabled' => true]);

            $member = TenantUser::factory()->create();
            $member->givePermissionTo(Permission::firstOrCreate([
                'name' => 'cancel modules',
                'guard_name' => 'tenant',
            ]));

            CancelModule::run($tenant, $member, 'alerts');

            $this->assertFalse(Module::where('name', 'alerts')->firstOrFail()->enabled);
        });
    }

    /**
     * Hits real Stripe test mode: creates a real subscription with the
     * module's price already on it, then asserts CancelModule removes that
     * price from Stripe as well as disabling the row.
     */
    public function test_cancelling_a_recurring_module_removes_the_subscription_item(): void
    {
        $priceId = Config::string('numerosis-billing.plans.0.monthly_id');

        if ($priceId === '') {
            $this->markTestSkipped('No Stripe test-mode price configured.');
        }

        // A distinct price from the base subscription's, same currency — a
        // subscription's items all have to share one currency, and adding
        // the same price id back on is a Stripe "duplicate price" error.
        $baseCurrency = Cashier::stripe()->prices->retrieve($priceId)->currency;
        $secondPriceId = $this->createRecurringPrice($baseCurrency);

        $tenant = Tenant::factory()->create();

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

        ModuleOffering::factory()->create([
            'slug' => 'alerts',
            'billing_mode' => ModuleBillingMode::Recurring,
            'monthly_id' => $secondPriceId,
            'yearly_id' => $secondPriceId,
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

        /** @var \Laravel\Cashier\SubscriptionItem $subscriptionItem */
        $subscriptionItem = $stripeSubscription->addPrice($secondPriceId)->items()->where('stripe_price', $secondPriceId)->firstOrFail();

        $owner = $this->ownerOf($tenant);

        $tenant->run(function () use ($tenant, $owner, $subscriptionItem): void {
            Module::create([
                'name' => 'alerts',
                'purchased_at' => now(),
                'enabled' => true,
                'stripe_subscription_item_id' => $subscriptionItem->stripe_id,
            ]);

            CancelModule::run($tenant, $owner, 'alerts');

            $module = Module::where('name', 'alerts')->firstOrFail();
            $this->assertFalse($module->enabled);
        });

        // Checked directly against Stripe rather than the local Eloquent
        // relation: the assertion needs to reflect what Stripe actually
        // billed, not a cached/reloaded local row.
        $stripeSub = Cashier::stripe()->subscriptions->retrieve($stripeSubscription->stripe_id, ['expand' => ['items']]);
        $prices = collect($stripeSub->items->data)->map(fn ($item) => $item->price->id);

        $this->assertNotContains($secondPriceId, $prices);
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
