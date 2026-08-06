<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Tests\Feature\Actions\Billing\Checkout;

use App\Models\Central\CentralUser;
use App\Models\Central\PaymentPlan;
use App\Models\Central\PendingTenantProvision;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Laravel\Cashier\Cashier;
use Nvade\Numerosis\Actions\Billing\Checkout\CreateInlineSubscription;
use Nvade\Numerosis\Enums\BillingCycle;
use Nvade\Numerosis\Tests\TestCase;

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
    use RefreshDatabase;

    public function test_it_creates_a_subscription_from_a_confirmed_setup_intent(): void
    {
        $priceId = Config::string('numerosis.billing.plans.0.monthly_id');

        if ($priceId === '') {
            $this->markTestSkipped('No Stripe test-mode price configured (STRIPE_STARTER_MONTHLY_PLAN).');
        }

        $user = CentralUser::factory()->create();
        $this->actingAs($user);

        PaymentPlan::create([
            'name' => 'Starter',
            'slug' => 'starter',
            'description' => 'Starter Plan',
            'monthly_id' => $priceId,
            'yearly_id' => $priceId,
            'monthly_price' => 1000,
            'yearly_price' => 10000,
            'available' => true,
            'trial_days' => 0,
        ]);

        $pending = PendingTenantProvision::factory()->create([
            'domain' => 'inline-sub-test',
            'global_id' => $user->global_id,
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
        // Billable::latestSubscription(). See .claude/rules/billing-checkout.md.
        $pending->refresh();
        $this->assertSame($subscription->stripe_id, $pending->stripe_subscription_id);
    }
}
