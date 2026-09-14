<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Tests\Feature\Actions\Billing\Checkout;

use App\Models\Central\CentralUser;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Cashier\Cashier;
use Laravel\Cashier\Subscription as CashierSubscription;
use Nvade\Numerosis\Actions\Billing\Checkout\CreateInlineSubscription;
use Nvade\Numerosis\Enums\Billing\BillingCycle;
use Nvade\Numerosis\Tests\Concerns\CreatesCheckoutFixtures;
use Nvade\Numerosis\Tests\TestCase;
use RuntimeException;

/**
 * Hits real Stripe test mode end to end: creates a customer, confirms a
 * SetupIntent with Stripe's pm_card_visa test token (standing in for what
 * Stripe.js would do client-side), then creates the subscription against it.
 * Uses the real STRIPE_STARTER_MONTHLY_PLAN test-mode price configured in
 * .env, the same one StartSubscriptionCheckoutTest's Stripe-hitting test
 * relies on existing.
 */
class CreateInlineSubscriptionTest extends TestCase
{
    use CreatesCheckoutFixtures;
    use RefreshDatabase;

    public function test_it_creates_a_subscription_from_a_confirmed_setup_intent(): void
    {
        $priceId = $this->starterPriceIdOrSkip();

        $user = CentralUser::factory()->create();
        $this->actingAs($user);

        $this->createStarterPlan($priceId);

        $pending = $this->reserve('inline-sub-test', $user, null, [
            'payment_plan' => 'starter',
            'billing_cycle' => BillingCycle::Monthly,
        ]);

        $customer = $user->createOrGetStripeCustomer();

        // Automatic tax (on by default — Cashier::calculateTaxes()) requires
        // a customer address. In production SyncBillingAddress writes this
        // before CreateInlineSubscription ever runs; this test calls the
        // action directly, so it sets the address up front instead.
        Cashier::stripe()->customers->update($customer->id, [
            'address' => [
                'line1' => '123 Main St',
                'city' => 'Amsterdam',
                'postal_code' => '1000AA',
                'country' => 'NL',
            ],
        ]);

        $setupIntent = Cashier::stripe()->setupIntents->create([
            'customer' => $customer->id,
            'payment_method' => 'pm_card_visa',
            'payment_method_types' => ['card'],
            'confirm' => true,
        ]);

        $this->assertSame('succeeded', $setupIntent->status);

        $subscription = CreateInlineSubscription::run($pending, (string) $setupIntent->payment_method);

        $this->assertSame($priceId, $subscription->stripe_price);
        $this->assertContains($subscription->stripe_status, ['active', 'trialing']);
        $this->assertSame('inline-sub-test', $subscription->asStripeSubscription()->metadata['domain'] ?? null);

        // Stamped onto the pending row as soon as the subscription exists —
        // this is what lets ResolveSetupIntent refuse a replayed subscribe()
        // and lets confirmed() settle by subscription id instead of
        // Billable::latestSubscription(). See .ai/rules/billing-checkout.md.
        $pending->refresh();
        $this->assertSame($subscription->stripe_id, $pending->stripe_subscription_id);
    }

    /**
     * The model mismatch used to be caught on the object Cashier returns,
     * which is after the customer has been charged and before the reservation
     * records the subscription id — the one outcome worse than either failing
     * early or succeeding. No Stripe credentials are needed to prove the
     * ordering: reaching Stripe at all would raise a Stripe exception instead.
     */
    public function test_a_misconfigured_subscription_model_fails_before_the_stripe_call(): void
    {
        $user = CentralUser::factory()->create();
        $this->actingAs($user);

        $this->createStarterPlan('price_never_used');

        $pending = $this->reserve('inline-sub-guard', $user, null, [
            'payment_plan' => 'starter',
            'billing_cycle' => BillingCycle::Monthly,
        ]);

        /** @var class-string<Model> $original */
        $original = Cashier::$subscriptionModel;
        Cashier::useSubscriptionModel(CashierSubscription::class);

        try {
            $this->expectException(RuntimeException::class);

            CreateInlineSubscription::run($pending, 'pm_card_visa');
        } finally {
            Cashier::useSubscriptionModel($original);

            $this->assertNull($pending->refresh()->stripe_subscription_id);
        }
    }
}
