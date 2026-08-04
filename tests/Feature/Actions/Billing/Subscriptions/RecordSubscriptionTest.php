<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Tests\Feature\Actions\Billing\Subscriptions;

use Nvade\Numerosis\Actions\Billing\Subscriptions\RecordSubscription;
use Nvade\Numerosis\Data\Billing\SubscriptionData;
use Nvade\Numerosis\Models\Central\CentralUser;
use Nvade\Numerosis\Models\Central\PaymentPlan;
use Nvade\Numerosis\Models\Central\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Nvade\Numerosis\Tests\TestCase;

class RecordSubscriptionTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_creates_a_subscription_from_data(): void
    {
        $user = CentralUser::factory()->create();
        $tenant = Tenant::create(['id' => 'tenant-'.str()->random(10)]);
        $plan = PaymentPlan::factory()->create();

        $data = SubscriptionData::from([
            'user_id' => (string) $user->id,
            'payment_plan_id' => (string) $plan->id,
            'stripe_id' => 'sub_123',
            'stripe_status' => 'active',
            'subscribable_id' => $tenant->id,
            'subscribable_type' => Tenant::class,
            'type' => 'default',
        ]);

        $subscription = RecordSubscription::run($data);

        $this->assertEquals('sub_123', $subscription->stripe_id);
        $this->assertEquals('active', $subscription->stripe_status);
        $this->assertEquals($tenant->id, $subscription->subscribable_id);
        $this->assertEquals(Tenant::class, $subscription->subscribable_type);
        $this->assertEquals($user->id, $subscription->user_id);

        // Explicit 'central' connection: Subscription uses CentralConnection
        // and commits immediately, but RefreshDatabase's transaction on the
        // default connection took its snapshot before that commit — the
        // default connection can't see it mid-test without naming the
        // connection here.
        $this->assertDatabaseHas('subscriptions', [
            'stripe_id' => 'sub_123',
            'subscribable_id' => $tenant->id,
        ], 'central');
    }

    public function test_it_creates_a_subscription_with_items(): void
    {
        $user = CentralUser::factory()->create();
        $tenant = Tenant::create(['id' => 'tenant-'.str()->random(10)]);
        $plan = PaymentPlan::factory()->create();

        $data = SubscriptionData::from([
            'user_id' => (string) $user->id,
            'payment_plan_id' => (string) $plan->id,
            'stripe_id' => 'sub_456',
            'stripe_status' => 'active',
            'subscribable_id' => $tenant->id,
            'subscribable_type' => Tenant::class,
            'items' => [
                [
                    'stripe_id' => 'si_1',
                    'stripe_product' => 'prod_1',
                    'stripe_price' => 'price_1',
                    'quantity' => 1,
                ],
                [
                    'stripe_id' => 'si_2',
                    'stripe_product' => 'prod_2',
                    'stripe_price' => 'price_2',
                    'quantity' => 2,
                ],
            ],
        ]);

        $subscription = RecordSubscription::run($data);

        $this->assertCount(2, $subscription->items);
        $this->assertDatabaseHas('subscription_items', [
            'subscription_id' => $subscription->id,
            'stripe_id' => 'si_1',
        ], 'central');
        $this->assertDatabaseHas('subscription_items', [
            'subscription_id' => $subscription->id,
            'stripe_id' => 'si_2',
        ], 'central');
    }
}
