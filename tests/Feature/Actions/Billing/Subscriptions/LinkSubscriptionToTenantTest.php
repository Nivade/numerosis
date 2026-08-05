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
use Nvade\Numerosis\Data\Tenancy\TenantProvisionData;
use Nvade\Numerosis\Data\Tenancy\TenantRegistrationData;
use Nvade\Numerosis\Enums\BillingCycle;
use Nvade\Numerosis\Tests\TestCase;
use Spatie\LaravelData\DataCollection;

class LinkSubscriptionToTenantTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_transfers_existing_subscription_to_tenant(): void
    {
        // Disable sync to avoid Stripe API calls
        Tenant::unsetEventDispatcher();

        $user = CentralUser::factory()->create();
        $tenant = Tenant::factory()->create(['stripe_id' => null]);
        $plan = PaymentPlan::factory()->create(['slug' => 'pro']);
        $subscription = Subscription::factory()->create([
            'stripe_id' => 'sub_123',
            'subscribable_id' => $user->global_id,
            'subscribable_type' => CentralUser::class,
            'payment_plan_id' => $plan->id,
        ]);

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

    private function provisionData(
        CentralUser $user,
        string $domain,
        string $paymentPlan,
        string $stripeCustomerId,
        string $stripeSubscriptionId,
    ): TenantProvisionData {
        return new TenantProvisionData(
            registration: TenantRegistrationData::from([
                'company_name' => 'Test Company',
                'domain' => $domain,
                'payment_plan' => $paymentPlan,
                'billing_cycle' => BillingCycle::Monthly,
                'global_id' => $user->global_id,
            ]),
            stripeCustomerId: $stripeCustomerId,
            stripeSubscriptionId: $stripeSubscriptionId,
            centralUserId: (string) $user->id,
        );
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
