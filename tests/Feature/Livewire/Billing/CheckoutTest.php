<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Tests\Feature\Livewire\Billing;

use App\Models\Central\CentralUser;
use App\Models\Central\PendingTenantProvision;
use App\Models\Central\Subscription;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Laravel\Cashier\Cashier;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Nvade\Numerosis\Actions\Billing\Checkout\ResolveCheckoutRegion;
use Nvade\Numerosis\Livewire\Billing\Checkout;
use Nvade\Numerosis\Testing\FakesStripe;
use Nvade\Numerosis\Tests\Concerns\CreatesCheckoutFixtures;
use Nvade\Numerosis\Tests\TestCase;

class CheckoutTest extends TestCase
{
    use CreatesCheckoutFixtures;
    use FakesStripe;
    use RefreshDatabase;

    public function test_it_renders_the_element_for_a_resumable_checkout(): void
    {
        $this->fakeStripe();
        $user = $this->signedInCustomer();

        $setupIntent = $this->openSetupIntentFor($user);

        $this->reserve('resume-render-test', $user, $setupIntent->id);

        Livewire::test(Checkout::class, ['domain' => 'resume-render-test'])
            ->assertSet('checkoutClientSecret', $setupIntent->client_secret)
            ->assertSet('paymentError', null);
    }

    /**
     * The curated per-region order is still live code — a host that binds a
     * region lookup back in gets it — even though `ResolveCheckoutRegion`
     * itself always answers null since torann/geoip was dropped in Phase 6 of
     * `.claude/plans/archive/humming-nibbling-flame.md`. Mocking the action rather
     * than a GeoIP facade is what keeps that path covered without the
     * dependency.
     */
    public function test_it_exposes_the_curated_payment_method_order_for_a_resolved_region(): void
    {
        $this->fakeStripe();
        $user = $this->signedInCustomer();

        $setupIntent = $this->openSetupIntentFor($user);

        $this->reserve('region-hit-test', $user, $setupIntent->id);

        ResolveCheckoutRegion::mock()->shouldReceive('handle')->andReturn('NL');

        Livewire::test(Checkout::class, ['domain' => 'region-hit-test'])
            ->assertSet('detectedCountry', 'NL')
            ->assertSet('paymentMethodOrder', Config::array('numerosis.billing.payment_methods.regions.NL'));
    }

    /**
     * With no lookup wired in this is now the only path production takes,
     * which is exactly why it is asserted against the real action rather
     * than a mock.
     */
    public function test_it_falls_back_to_the_default_payment_method_order_when_the_region_is_unresolved(): void
    {
        $this->fakeStripe();
        $user = $this->signedInCustomer();

        $setupIntent = $this->openSetupIntentFor($user);

        $this->reserve('region-miss-test', $user, $setupIntent->id);

        Livewire::test(Checkout::class, ['domain' => 'region-miss-test'])
            ->assertSet('detectedCountry', null)
            ->assertSet('paymentMethodOrder', Config::array('numerosis.billing.payment_methods.default_order'));
    }

    public function test_it_surfaces_an_error_for_another_users_domain(): void
    {
        $this->fakeStripe();
        $victim = CentralUser::factory()->create();
        $attacker = CentralUser::factory()->create();
        $this->actingAs($attacker);

        $this->reserve('not-yours-render', $victim, 'seti_not_the_attackers');

        Livewire::test(Checkout::class, ['domain' => 'not-yours-render'])
            ->assertSet('checkoutClientSecret', null)
            ->assertSet('paymentError', __('numerosis::billing.checkout.foreign_session'));
    }

    /**
     * Every public entry point on the component reaches a reservation, and
     * each used to re-decide ownership for itself with a hand-rolled
     * `global_id` comparison. They all go through `AssertReservationIsOwned`
     * now, so this pins them together: a new entry point that skips it is the
     * failure this catches.
     */
    public function test_every_entry_point_refuses_a_foreign_reservation(): void
    {
        $this->fakeStripe();
        $victim = CentralUser::factory()->create();
        $attacker = CentralUser::factory()->create();
        $this->actingAs($attacker);

        $setupIntent = $this->openSetupIntentFor($victim);

        $this->reserve('not-yours-entry-points', $victim, $setupIntent->id);

        foreach (['subscribeWithSavedPaymentMethod', 'confirmed'] as $method) {
            $component = Livewire::test(Checkout::class, ['domain' => 'not-yours-entry-points']);

            $method === 'confirmed'
                ? $component->call('confirmed')
                : $component->call($method, 'pm_card_visa');

            $component
                ->assertSet('paymentError', __('numerosis::billing.checkout.foreign_session'))
                ->assertNoRedirect();
        }

        Livewire::test(Checkout::class, ['domain' => 'not-yours-entry-points'])
            ->call('subscribe', $setupIntent->id)
            ->assertSet('paymentError', __('numerosis::billing.checkout.foreign_session'))
            ->assertNoRedirect();

        $this->assertNull(PendingTenantProvision::find('not-yours-entry-points')?->stripe_subscription_id);
    }

    /**
     * confirmed() is a public Livewire method reachable with no 3DS ever
     * having happened. Before this fix it settled via
     * Billable::latestSubscription(), which has no link to $pendingDomain —
     * a user who already held an unrelated active subscription (an existing,
     * already-paid tenant) could call confirmed() on a brand new pending
     * reservation and get a second tenant provisioned for free. See
     * .ai/rules/billing-checkout.md.
     */
    public function test_confirming_does_not_settle_an_unrelated_active_subscription(): void
    {
        $this->fakeStripe();
        $user = $this->signedInCustomer();

        $setupIntent = $this->openSetupIntentFor($user);

        $this->reserve('confirm-unrelated-test', $user, $setupIntent->id);

        Subscription::create([
            'subscribable_id' => $user->id,
            'subscribable_type' => CentralUser::class,
            'type' => 'default',
            'stripe_id' => 'sub_unrelated_active',
            'stripe_status' => 'active',
            'stripe_price' => 'price_test',
            'quantity' => 1,
        ]);

        Livewire::test(Checkout::class, ['domain' => 'confirm-unrelated-test'])
            ->call('confirmed')
            ->assertSet('paymentError', __('numerosis::billing.checkout.confirmation_failed'))
            ->assertNoRedirect();

        $pending = PendingTenantProvision::find('confirm-unrelated-test');
        $this->assertNotNull($pending);
        $this->assertNull($pending->stripe_subscription_id);
    }

    public function test_the_pending_domain_cannot_be_set_by_the_client(): void
    {
        $this->fakeStripe();
        $this->actingAs(CentralUser::factory()->create());

        $this->expectException(CannotUpdateLockedPropertyException::class);

        Livewire::test(Checkout::class, ['domain' => 'locked-domain-test'])
            ->set('pendingDomain', 'someone-elses-domain');
    }

    /**
     * ResolveSetupIntent proves the resolved row belongs to whoever is asking,
     * but not that it is the row this component was mounted for — and settle()
     * re-reads by $pendingDomain. A user holding two reservations could
     * otherwise confirm domain-a's SetupIntent while mounted on domain-b, have
     * b provisioned from it, and still resume a off the same subscription:
     * two tenants, one payment.
     */
    public function test_it_refuses_a_setup_intent_belonging_to_a_different_reservation(): void
    {
        $this->fakeStripe();
        $user = $this->signedInCustomer();

        $customer = $user->createOrGetStripeCustomer();

        $intentForA = Cashier::stripe()->setupIntents->create([
            'customer' => $customer->id,
            'payment_method_types' => ['card'],
            'usage' => 'off_session',
        ]);

        Cashier::stripe()->setupIntents->confirm($intentForA->id, [
            'payment_method' => 'pm_card_visa',
        ]);

        $intentForB = Cashier::stripe()->setupIntents->create([
            'customer' => $customer->id,
            'payment_method_types' => ['card'],
        ]);

        $this->reserve('cross-row-a', $user, $intentForA->id);
        $this->reserve('cross-row-b', $user, $intentForB->id);

        Livewire::test(Checkout::class, ['domain' => 'cross-row-b'])
            ->call('subscribe', $intentForA->id)
            ->assertSet('paymentError', __('numerosis::billing.checkout.session_expired'))
            ->assertNoRedirect();

        $this->assertNull(PendingTenantProvision::find('cross-row-a')?->stripe_subscription_id);
        $this->assertNull(PendingTenantProvision::find('cross-row-b')?->stripe_subscription_id);
    }

    public function test_it_refuses_to_confirm_without_a_resumable_pending_row(): void
    {
        $this->fakeStripe();
        $this->actingAs(CentralUser::factory()->create());

        Livewire::test(Checkout::class, ['domain' => 'no-such-pending-row'])
            ->call('confirmed')
            ->assertSet('paymentError', __('numerosis::billing.checkout.confirmation_failed'))
            ->assertNoRedirect();
    }

    public function test_it_prefills_the_saved_billing_address_for_a_returning_customer(): void
    {
        $this->fakeStripe();
        $user = $this->signedInCustomer();

        $customer = $user->createOrGetStripeCustomer();

        $stripe = Cashier::stripe();
        $stripe->customers->update($customer->id, [
            'name' => 'John Doe',
            'address' => [
                'line1' => '123 Main St',
                'city' => 'San Francisco',
                'state' => 'CA',
                'postal_code' => '94103',
                'country' => 'US',
            ],
        ]);

        $setupIntent = $this->openSetupIntentFor($user);

        $this->reserve('prefill-test', $user, $setupIntent->id);

        Livewire::test(Checkout::class, ['domain' => 'prefill-test'])
            ->assertSet('savedBillingAddress', [
                'name' => 'John Doe',
                'address' => [
                    'line1' => '123 Main St',
                    'line2' => null,
                    'city' => 'San Francisco',
                    'state' => 'CA',
                    'postal_code' => '94103',
                    'country' => 'US',
                ],
            ]);
    }

    public function test_it_leaves_the_saved_billing_address_null_for_a_brand_new_customer(): void
    {
        $this->fakeStripe();
        $user = $this->signedInCustomer();

        $setupIntent = $this->openSetupIntentFor($user);

        $this->reserve('no-prefill-test', $user, $setupIntent->id);

        Livewire::test(Checkout::class, ['domain' => 'no-prefill-test'])
            ->assertSet('savedBillingAddress', null)
            ->assertSet('savedBillingFetchFailed', false);
    }

    public function test_it_shows_a_banner_when_fetching_saved_billing_details_fails(): void
    {
        $this->fakeStripe();
        $user = CentralUser::factory()->create(['stripe_id' => 'cus_invalid']);
        $this->actingAs($user);

        $this->reserve('fetch-fail-test', $user, 'seti_test');

        Livewire::test(Checkout::class, ['domain' => 'fetch-fail-test'])
            ->assertSet('savedBillingFetchFailed', true);
    }

    public function test_it_lists_reusable_payment_methods_for_a_returning_customer(): void
    {
        $this->fakeStripe();
        $user = $this->signedInCustomer();

        $customer = $user->createOrGetStripeCustomer();
        $stripe = Cashier::stripe();

        $pm = $stripe->paymentMethods->create([
            'type' => 'card',
            'card' => ['token' => 'tok_visa'],
        ]);

        $stripe->paymentMethods->attach($pm->id, ['customer' => $customer->id]);

        $setupIntent = $this->openSetupIntentFor($user);

        $this->reserve('payment-methods-test', $user, $setupIntent->id);

        Livewire::test(Checkout::class, ['domain' => 'payment-methods-test'])
            ->assertSet('savedPaymentMethodsFetchFailed', false);
    }

    public function test_it_shows_a_banner_when_fetching_saved_payment_methods_fails(): void
    {
        $this->fakeStripe();
        $user = CentralUser::factory()->create(['stripe_id' => 'cus_invalid']);
        $this->actingAs($user);

        $this->reserve('payment-methods-fetch-fail-test', $user, 'seti_test');

        Livewire::test(Checkout::class, ['domain' => 'payment-methods-fetch-fail-test'])
            ->assertSet('savedPaymentMethodsFetchFailed', true);
    }

    public function test_it_refuses_a_saved_payment_method_belonging_to_a_different_customer(): void
    {
        $this->fakeStripe();
        $victim = CentralUser::factory()->create();
        $attacker = CentralUser::factory()->create();
        $this->actingAs($attacker);

        $victimCustomer = $victim->createOrGetStripeCustomer();
        $attacker->createOrGetStripeCustomer();
        $stripe = Cashier::stripe();

        $pm = $stripe->paymentMethods->create([
            'type' => 'card',
            'card' => ['token' => 'tok_visa'],
        ]);

        $stripe->paymentMethods->attach($pm->id, ['customer' => $victimCustomer->id]);

        $this->reserve('cross-customer-test', $attacker, null);

        Livewire::test(Checkout::class, ['domain' => 'cross-customer-test'])
            ->call('subscribeWithSavedPaymentMethod', $pm->id)
            ->assertSet('paymentError', __('numerosis::billing.checkout.saved_payment_method_unavailable'))
            ->assertNoRedirect();

        $pending = PendingTenantProvision::find('cross-customer-test');
        $this->assertNotNull($pending);
        $this->assertNull($pending->stripe_subscription_id);
    }

    public function test_saved_payment_methods_and_saved_billing_address_cannot_be_set_by_the_client(): void
    {
        $this->fakeStripe();
        $this->actingAs(CentralUser::factory()->create());

        $this->expectException(CannotUpdateLockedPropertyException::class);

        Livewire::test(Checkout::class, ['domain' => 'locked-props-test'])
            ->set('savedPaymentMethods', [['id' => 'pm_test']]);
    }
}
