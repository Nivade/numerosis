<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Tests\Feature\Actions\Billing\Subscriptions;

use App\Models\Central\CentralUser;
use App\Models\Central\PaymentPlan;
use App\Models\Central\Subscription;
use App\Models\Central\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Nvade\Numerosis\Actions\Billing\Subscriptions\LinkSubscriptionToTenant;
use Nvade\Numerosis\Data\Billing\StripeSubscriptionData;
use Nvade\Numerosis\Data\Billing\SubscriptionItemData;
use Nvade\Numerosis\Models\Central\Subscription as PackageSubscription;
use Nvade\Numerosis\Tests\Concerns\BuildsTenantProvisionData;
use Nvade\Numerosis\Tests\TestCase;
use Spatie\LaravelData\DataCollection;

class LinkSubscriptionToTenantTest extends TestCase
{
    use BuildsTenantProvisionData;
    use RefreshDatabase;

    public function test_it_transfers_existing_subscription_to_tenant(): void
    {
        // Disable sync to avoid Stripe API calls
        Tenant::unsetEventDispatcher();

        $user = CentralUser::factory()->create();
        $tenant = Tenant::factory()->create(['stripe_id' => null]);
        $plan = PaymentPlan::factory()->create(['slug' => 'pro']);
        $subscription = $this->subscriptionOwnedBy($user, 'sub_123', $plan->id);

        LinkSubscriptionToTenant::run(
            $this->provisionData($user, 'test', 'pro', 'cus_123', 'sub_123'),
            $this->stripeSubscription('sub_123'),
            $tenant,
        );

        $tenant->refresh();
        $subscription->refresh();

        $this->assertEquals('cus_123', $tenant->stripe_id);
        $this->assertEquals($tenant->id, $subscription->subscribable_id);
        $this->assertEquals(Tenant::class, $subscription->subscribable_type);
    }

    public function test_it_backfills_payment_plan_id_when_transferring_a_subscription_created_without_one(): void
    {
        // Disable sync to avoid Stripe API calls
        Tenant::unsetEventDispatcher();

        $user = CentralUser::factory()->create();
        $tenant = Tenant::factory()->create(['stripe_id' => null]);
        $plan = PaymentPlan::factory()->create(['slug' => 'pro']);
        $subscription = $this->subscriptionOwnedBy($user, 'sub_789', null);

        LinkSubscriptionToTenant::run(
            $this->provisionData($user, 'test3', 'pro', 'cus_789', 'sub_789'),
            $this->stripeSubscription('sub_789'),
            $tenant,
        );

        $subscription->refresh();

        $this->assertEquals($plan->id, $subscription->payment_plan_id);
    }

    public function test_it_creates_subscription_manually_if_it_does_not_exist(): void
    {
        Tenant::unsetEventDispatcher();

        $user = CentralUser::factory()->create();
        $tenant = Tenant::factory()->create(['stripe_id' => null]);
        $plan = PaymentPlan::factory()->create(['monthly_id' => 'price_123']);

        $stripeSubscription = new \Stripe\Subscription('sub_456');
        $stripeSubscription->status = 'active';
        $stripeSubscription->trial_end = null;
        $stripeSubscription->items = (object) [
            'data' => [
                (object) [
                    'id' => 'si_123',
                    'price' => (object) [
                        'id' => 'price_123',
                        'product' => 'prod_123',
                    ],
                    'quantity' => 1,
                ],
            ],
        ];

        LinkSubscriptionToTenant::run(
            $this->provisionData($user, 'test2', $plan->slug, 'cus_456', 'sub_456'),
            StripeSubscriptionData::fromStripe($stripeSubscription),
            $tenant,
        );

        $tenant->refresh();
        $subscription = Subscription::where('stripe_id', 'sub_456')->first();

        $this->assertNotNull($subscription);
        $this->assertEquals('cus_456', $tenant->stripe_id);
        $this->assertEquals($tenant->id, $subscription->subscribable_id);
        $this->assertEquals(Tenant::class, $subscription->subscribable_type);
        $this->assertEquals($user->id, $subscription->user_id);
        $this->assertEquals($plan->id, $subscription->payment_plan_id);
    }

    /**
     * The morph is keyed on the owner's primary key, which is what Cashier's
     * own `subscriptions()->create()` writes. A `global_id` here produces a
     * row whose owner silently resolves to null, and intermittently an
     * `ErrorException`: `subscribable_id` is a string column while
     * `CentralUser::getKeyType()` is `'int'`, so the eager load runs
     * `whereIntegerInRaw` and casts every value — fine for `"1"`, fatal for a
     * UUID shaped like `3e106911-…`.
     *
     * Returns the package's own model rather than the host subclass the
     * factory builds at runtime: Larastan resolves `Subscription::factory()`
     * through the factory's generic, which names the package class.
     */
    private function subscriptionOwnedBy(CentralUser $user, string $stripeId, ?int $paymentPlanId): PackageSubscription
    {
        return Subscription::factory()->create([
            'stripe_id' => $stripeId,
            'subscribable_id' => $user->getKey(),
            'subscribable_type' => CentralUser::class,
            'payment_plan_id' => $paymentPlanId,
        ]);
    }

    /**
     * The transfer branch reads nothing but the id, so the rest is filler.
     */
    private function stripeSubscription(string $id): StripeSubscriptionData
    {
        return new StripeSubscriptionData(
            id: $id,
            status: 'active',
            priceId: null,
            quantity: null,
            trialEndsAt: null,
            items: new DataCollection(SubscriptionItemData::class, []),
        );
    }
}
