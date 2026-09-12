<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Tests\Concerns;

use App\Models\Central\CentralUser;
use App\Models\Central\PaymentPlan;
use App\Models\Central\TenantProvision;
use Laravel\Cashier\Cashier;
use Stripe\PaymentMethod;
use Stripe\SetupIntent;

/**
 * The arrange half of a checkout test: a customer, a SetupIntent, and the
 * reservation the two are settled against.
 *
 * Every method here talks to whichever Stripe client is installed. Under
 * {@see \Nvade\Numerosis\Testing\FakesStripe} that is the in-memory fake;
 * the live-mode tests call `starterPriceIdOrSkip()` first and get the real
 * API.
 */
trait CreatesCheckoutFixtures
{
    /**
     * The test-mode price the live-Stripe tests subscribe against, or a skip.
     *
     * Nothing here can mint a price, so a run without one configured has no
     * honest result to report.
     */
    protected function starterPriceIdOrSkip(): string
    {
        $priceId = getenv('STRIPE_STARTER_MONTHLY_PLAN') ?: '';

        if ($priceId === '') {
            $this->markTestSkipped('No Stripe test-mode price configured (STRIPE_STARTER_MONTHLY_PLAN).');
        }

        return $priceId;
    }

    /**
     * A signed-in central user, the account every checkout here is reserved
     * and billed against.
     */
    protected function signedInCustomer(): CentralUser
    {
        $user = CentralUser::factory()->create();

        $this->actingAs($user);

        return $user;
    }

    /**
     * The `starter` plan, priced against a real Stripe price on both cycles.
     */
    protected function createStarterPlan(string $priceId): PaymentPlan
    {
        return PaymentPlan::factory()->create([
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
    }

    /**
     * A card carrying a billing address.
     *
     * Automatic tax (on by default — `Cashier::calculateTaxes()`) requires a
     * customer address, which `SyncBillingAddress` writes from the
     * PaymentMethod's `billing_details`. `pm_card_visa` carries none, so this
     * stands in for what the Address Element would have produced.
     */
    protected function cardWithBillingAddress(string $country = 'NL'): PaymentMethod
    {
        return Cashier::stripe()->paymentMethods->create([
            'type' => 'card',
            'card' => ['token' => 'tok_visa'],
            'billing_details' => [
                'address' => [
                    'line1' => '123 Main St',
                    'city' => 'Amsterdam',
                    'postal_code' => '1000AA',
                    'country' => $country,
                ],
            ],
        ]);
    }

    /**
     * A confirmed SetupIntent for the user's customer.
     *
     * `pm_card_visa` is Stripe's shared test token and carries no address, so
     * anything reaching automatic tax passes the id of a
     * {@see cardWithBillingAddress()} instead.
     */
    protected function confirmedSetupIntentFor(CentralUser $user, string $paymentMethodId = 'pm_card_visa'): SetupIntent
    {
        return Cashier::stripe()->setupIntents->create([
            'customer' => $user->createOrGetStripeCustomer()->id,
            'payment_method' => $paymentMethodId,
            'payment_method_types' => ['card'],
            'confirm' => true,
        ]);
    }

    /**
     * An unconfirmed SetupIntent for the user's customer — what the checkout
     * component mounts against before the customer has paid anything.
     */
    protected function openSetupIntentFor(CentralUser $user): SetupIntent
    {
        return Cashier::stripe()->setupIntents->create([
            'customer' => $user->createOrGetStripeCustomer()->id,
            'payment_method_types' => ['card'],
        ]);
    }

    /**
     * A reservation of `$domain` for `$user`, pointed at `$setupIntentId`.
     *
     * @param  array<string, mixed>  $attributes
     */
    protected function reserve(string $domain, CentralUser $user, ?string $setupIntentId, array $attributes = []): TenantProvision
    {
        return TenantProvision::factory()->create([
            'slug' => $domain,
            'global_id' => $user->global_id,
            'stripe_setup_intent_id' => $setupIntentId,
            ...$attributes,
        ]);
    }
}
