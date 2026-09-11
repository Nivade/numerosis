<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Tests\Feature\Actions\Billing\Checkout;

use App\Models\Central\CentralUser;
use App\Models\Central\PendingTenantProvision;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Cashier\Cashier;
use Nvade\Numerosis\Enums\Billing\BillingCycle;
use Nvade\Numerosis\Facades\Billing;
use Nvade\Numerosis\Features\FeatureRegistry;
use Nvade\Numerosis\Tests\Concerns\CreatesCheckoutFixtures;
use Nvade\Numerosis\Tests\TestCase;

/**
 * This is the test that keeps the multi-method promise honest — see
 * custom-checkout.md's Tests section. It must exist before any
 * redirect-flavoured payment method (iDEAL, Bancontact) is enabled, even
 * though cards-only Phase 2 never actually reaches this route in production.
 */
class CompleteRedirectCheckoutTest extends TestCase
{
    use CreatesCheckoutFixtures;
    use RefreshDatabase;

    public function test_it_provisions_once_for_the_owning_customer(): void
    {
        $fake = Billing::fake();

        $this->createStarterPlan($this->starterPriceIdOrSkip());

        $user = $this->signedInCustomer();

        $setupIntent = $this->confirmedSetupIntentFor($user, $this->cardWithBillingAddress()->id);

        $this->reserve('return-route-test', $user, $setupIntent->id, [
            'payment_plan' => 'starter',
            'billing_cycle' => BillingCycle::Monthly,
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
     * .ai/rules/billing-checkout.md.
     */
    public function test_a_replayed_return_visit_does_not_create_a_second_subscription(): void
    {
        $fake = Billing::fake();

        $this->createStarterPlan($this->starterPriceIdOrSkip());

        $user = $this->signedInCustomer();

        $setupIntent = $this->confirmedSetupIntentFor($user, $this->cardWithBillingAddress()->id);

        $this->reserve('replay-return-test', $user, $setupIntent->id, [
            'payment_plan' => 'starter',
            'billing_cycle' => BillingCycle::Monthly,
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

        $subscriptions = Cashier::stripe()->subscriptions->all(['customer' => $user->stripeIdOrFail()]);
        $this->assertCount(1, $subscriptions->data);
    }

    public function test_it_refuses_a_setup_intent_belonging_to_another_customer(): void
    {
        $victim = CentralUser::factory()->create();
        $attacker = CentralUser::factory()->create();

        $this->reserve('stolen-reservation', $victim, 'seti_not_the_attackers');

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
        FeatureRegistry::forceForTesting([]);

        $victim = CentralUser::factory()->create();
        $attacker = CentralUser::factory()->create();

        $this->reserve('stolen-reservation-wizard-off', $victim, 'seti_not_the_attackers_wizard_off');

        $this->actingAs($attacker)
            ->get(route('checkout.subscription.return', ['setup_intent' => 'seti_not_the_attackers_wizard_off']))
            ->assertRedirect(route('home'))
            ->assertSessionHas('error');
    }
}
