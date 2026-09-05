<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Tests\Feature\Actions\Billing\Subscriptions;

use App\Models\Central\CentralUser;
use App\Models\Central\PaymentPlan;
use App\Models\Central\Subscription;
use App\Models\Central\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Nvade\Numerosis\Actions\Billing\Subscriptions\LinkSubscriptionToTenant;
use Nvade\Numerosis\Data\Billing\StripeSubscriptionData;
use Nvade\Numerosis\Tests\Concerns\BuildsTenantProvisionData;
use Nvade\Numerosis\Tests\Concerns\DisablesWebhookSignature;
use Nvade\Numerosis\Tests\TestCase;

/**
 * Cashier's own webhook write (WebhookController::handleCustomerSubscriptionCreated,
 * via parent::) and LinkSubscriptionToTenant's manual write both target the
 * same `subscriptions` row for a given stripe_id. Neither ordering should
 * ever produce two rows or duplicate items — see
 * .claude/plans/archive/vendor-duplication-cleanup.md #1.
 */
class SubscriptionDualWriterTest extends TestCase
{
    use BuildsTenantProvisionData;
    use DisablesWebhookSignature;
    use RefreshDatabase;

    public function test_redirect_then_webhook_produces_one_row_and_one_item_per_price(): void
    {
        Tenant::unsetEventDispatcher();

        $user = CentralUser::factory()->create();
        $tenant = Tenant::factory()->create(['stripe_id' => null]);
        PaymentPlan::factory()->create(['slug' => 'pro', 'monthly_id' => 'price_dup1']);

        // Redirect wins the race: LinkSubscriptionToTenant creates the row
        // manually because the webhook has not landed yet.
        LinkSubscriptionToTenant::run(
            $this->provisionData($user, 'dup1', 'pro', 'cus_dup1', 'sub_dup1'),
            $this->stripeSubscriptionData('sub_dup1', 'price_dup1', 'si_dup1', 'prod_dup1'),
            $tenant,
        );

        $tenant->refresh();
        $this->assertEquals('cus_dup1', $tenant->stripe_id);

        // Webhook arrives afterwards. Tenant now carries the matching
        // stripe_id, so Cashier's own write resolves the billable and would
        // hit the same subscriptions row.
        $this->postJson(Config::string('numerosis.billing.webhook_path', 'billing/webhook'), $this->webhookPayload(
            'sub_dup1',
            'cus_dup1',
            'price_dup1',
            'si_dup1',
            'prod_dup1',
        ))->assertOk();

        $this->assertEquals(1, Subscription::where('stripe_id', 'sub_dup1')->count());

        $subscription = Subscription::where('stripe_id', 'sub_dup1')->firstOrFail();
        $this->assertCount(1, $subscription->items);
    }

    public function test_webhook_then_redirect_produces_one_row_and_one_item_per_price(): void
    {
        Tenant::unsetEventDispatcher();

        $user = CentralUser::factory()->create();
        // Tenant's stripe_id is already synced (e.g. a prior customer.created
        // webhook), so Cashier's write resolves the billable on its first pass.
        $tenant = Tenant::factory()->create(['stripe_id' => 'cus_dup2']);
        PaymentPlan::factory()->create(['slug' => 'pro', 'monthly_id' => 'price_dup2']);

        $this->postJson(Config::string('numerosis.billing.webhook_path', 'billing/webhook'), $this->webhookPayload(
            'sub_dup2',
            'cus_dup2',
            'price_dup2',
            'si_dup2',
            'prod_dup2',
        ))->assertOk();

        $this->assertEquals(1, Subscription::where('stripe_id', 'sub_dup2')->count());

        // The redirect's provisioning path runs afterwards for the same
        // subscription and must find, not duplicate, the webhook's row.
        LinkSubscriptionToTenant::run(
            $this->provisionData($user, 'dup2', 'pro', 'cus_dup2', 'sub_dup2'),
            $this->stripeSubscriptionData('sub_dup2', 'price_dup2', 'si_dup2', 'prod_dup2'),
            $tenant,
        );

        $this->assertEquals(1, Subscription::where('stripe_id', 'sub_dup2')->count());

        $subscription = Subscription::where('stripe_id', 'sub_dup2')->firstOrFail();
        $this->assertCount(1, $subscription->items);
        $this->assertEquals($tenant->id, $subscription->subscribable_id);
        $this->assertEquals(Tenant::class, $subscription->subscribable_type);
    }

    private function stripeSubscriptionData(
        string $subscriptionId,
        string $priceId,
        string $itemId,
        string $productId,
    ): StripeSubscriptionData {
        $stripeSubscription = new \Stripe\Subscription($subscriptionId);
        $stripeSubscription->status = 'active';
        $stripeSubscription->trial_end = null;
        // Stripe\Subscription::$items is typed as a Stripe\Collection, but the
        // SDK's own StripeObject::__set has no such constraint at runtime —
        // this stands in for the raw JSON shape StripeSubscriptionData::fromStripe()
        // actually reads, without spinning up a real Stripe\Collection.
        // @phpstan-ignore assign.propertyType
        $stripeSubscription->items = (object) [
            'data' => [
                (object) [
                    'id' => $itemId,
                    'price' => (object) ['id' => $priceId, 'product' => $productId],
                    'quantity' => 1,
                ],
            ],
        ];

        return StripeSubscriptionData::fromStripe($stripeSubscription);
    }

    /**
     * @return array<string, mixed>
     */
    private function webhookPayload(
        string $subscriptionId,
        string $customerId,
        string $priceId,
        string $itemId,
        string $productId,
    ): array {
        return [
            'id' => 'evt_'.$subscriptionId,
            'type' => 'customer.subscription.created',
            'data' => [
                'object' => [
                    'id' => $subscriptionId,
                    'customer' => $customerId,
                    'status' => 'active',
                    'trial_end' => null,
                    'metadata' => [],
                    'items' => [
                        'data' => [
                            [
                                'id' => $itemId,
                                'price' => ['id' => $priceId, 'product' => $productId],
                                'quantity' => 1,
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }
}
