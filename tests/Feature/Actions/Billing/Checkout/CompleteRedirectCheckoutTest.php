<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Tests\Feature\Actions\Billing\Checkout;

use App\Models\Central\CentralUser;
use App\Models\Central\PaymentPlan;
use App\Models\Central\PendingTenantProvision;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Laravel\Cashier\Cashier;
use Nvade\Numerosis\Enums\BillingCycle;
use Nvade\Numerosis\Facades\Billing;
use Nvade\Numerosis\Support\Features;
use Nvade\Numerosis\Tests\TestCase;

/**
 * This is the test that keeps the multi-method promise honest — see
 * custom-checkout.md's Tests section. It must exist before any
 * redirect-flavoured payment method (iDEAL, Bancontact) is enabled, even
 * though cards-only Phase 2 never actually reaches this route in production.
 */
class CompleteRedirectCheckoutTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_provisions_once_for_the_owning_customer(): void
    {
        $fake = Billing::fake();

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

        $customer = $user->createOrGetStripeCustomer();

        // Automatic tax (on by default — Cashier::calculateTaxes()) requires
        // a customer address, which SyncBillingAddress writes from the
        // PaymentMethod's billing_details. pm_card_visa carries none, so a
        // real payment method with an address stands in for what the
        // Address Element would have produced.
        $paymentMethod = Cashier::stripe()->paymentMethods->create([
            'type' => 'card',
            'card' => ['token' => 'tok_visa'],
            'billing_details' => [
                'address' => [
                    'line1' => '123 Main St',
                    'city' => 'Amsterdam',
                    'postal_code' => '1000AA',
                    'country' => 'NL',
                ],
            ],
        ]);

        $setupIntent = Cashier::stripe()->setupIntents->create([
            'customer' => $customer->id,
            'payment_method' => $paymentMethod->id,
            'payment_method_types' => ['card'],
            'confirm' => true,
        ]);

        PendingTenantProvision::factory()->create([
            'domain' => 'return-route-test',
            'global_id' => $user->global_id,
            'payment_plan' => 'starter',
            'billing_cycle' => BillingCycle::Monthly,
            'stripe_setup_intent_id' => $setupIntent->id,
        ]);

        $this->get(route('checkout.subscription.return', ['setup_intent' => $setupIntent->id]))
            ->assertRedirect(route('tenants.mine'));

        $fake->assertTenantProvisioned('return-route-test');

        $pending = PendingTenantProvision::find('return-route-test');
        $this->assertNotNull($pending);
        $this->assertNotNull($pending->stripe_subscription_id);
    }

    /**
     * A re-hit of this route for a SetupIntent that already turned into a
     * subscription — the browser back button, a stale bookmark, a doubled
     * bank redirect — must not run CreateInlineSubscription a second time
     * and charge the customer twice. It should land the already-paid
     * customer on their tenant, not surface a refusal. See
     * .claude/rules/billing-checkout.md.
     */
    public function test_a_replayed_return_visit_does_not_create_a_second_subscription(): void
    {
        $fake = Billing::fake();

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

        $customer = $user->createOrGetStripeCustomer();

        $paymentMethod = Cashier::stripe()->paymentMethods->create([
            'type' => 'card',
            'card' => ['token' => 'tok_visa'],
            'billing_details' => [
                'address' => [
                    'line1' => '123 Main St',
                    'city' => 'Amsterdam',
                    'postal_code' => '1000AA',
                    'country' => 'NL',
                ],
            ],
        ]);

        $setupIntent = Cashier::stripe()->setupIntents->create([
            'customer' => $customer->id,
            'payment_method' => $paymentMethod->id,
            'payment_method_types' => ['card'],
            'confirm' => true,
        ]);

        PendingTenantProvision::factory()->create([
            'domain' => 'replay-return-test',
            'global_id' => $user->global_id,
            'payment_plan' => 'starter',
            'billing_cycle' => BillingCycle::Monthly,
            'stripe_setup_intent_id' => $setupIntent->id,
        ]);

        $this->get(route('checkout.subscription.return', ['setup_intent' => $setupIntent->id]))
            ->assertRedirect(route('tenants.mine'));

        $fake->assertTenantProvisioned('replay-return-test');

        $pending = PendingTenantProvision::find('replay-return-test');
        $this->assertNotNull($pending);
        $subscriptionId = $pending->stripe_subscription_id;
        $this->assertNotNull($subscriptionId);

        // Replay: same setup_intent hits the return route a second time.
        $this->get(route('checkout.subscription.return', ['setup_intent' => $setupIntent->id]))
            ->assertRedirect(route('tenants.mine'))
            ->assertSessionHas('success');

        $pending = PendingTenantProvision::find('replay-return-test');
        $this->assertSame($subscriptionId, $pending?->stripe_subscription_id);

        $subscriptions = Cashier::stripe()->subscriptions->all(['customer' => $customer->id]);
        $this->assertCount(1, $subscriptions->data);
    }

    public function test_it_refuses_a_setup_intent_belonging_to_another_customer(): void
    {
        $victim = CentralUser::factory()->create();
        $attacker = CentralUser::factory()->create();

        PendingTenantProvision::factory()->create([
            'domain' => 'stolen-reservation',
            'global_id' => $victim->global_id,
            'stripe_setup_intent_id' => 'seti_not_the_attackers',
        ]);

        $this->actingAs($attacker)
            ->get(route('checkout.subscription.return', ['setup_intent' => 'seti_not_the_attackers']))
            ->assertRedirect(route('tenants.create'))
            ->assertSessionHas('error');
    }

    /**
     * This checkout route is reachable independent of the registration
     * wizard, so it must not redirect to a route that no longer exists once
     * RegistrationWizardFeature is off.
     */
    public function test_it_redirects_home_instead_of_the_wizard_when_the_wizard_is_disabled(): void
    {
        Features::forceForTesting([]);

        $victim = CentralUser::factory()->create();
        $attacker = CentralUser::factory()->create();

        PendingTenantProvision::factory()->create([
            'domain' => 'stolen-reservation-wizard-off',
            'global_id' => $victim->global_id,
            'stripe_setup_intent_id' => 'seti_not_the_attackers_wizard_off',
        ]);

        $this->actingAs($attacker)
            ->get(route('checkout.subscription.return', ['setup_intent' => 'seti_not_the_attackers_wizard_off']))
            ->assertRedirect(route('home'))
            ->assertSessionHas('error');
    }
}
