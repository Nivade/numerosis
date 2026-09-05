<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Tests\Feature\Http\Controllers\Billing;

use App\Models\Central\CentralUser;
use App\Models\Central\PendingTenantProvision;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Event;
use Laravel\Cashier\Cashier;
use Nvade\Numerosis\Enums\Billing\BillingCycle;
use Nvade\Numerosis\Events\Billing\CheckoutCompleted;
use Nvade\Numerosis\Facades\Billing;
use Nvade\Numerosis\Tests\Concerns\CreatesCheckoutFixtures;
use Nvade\Numerosis\Tests\Concerns\DisablesWebhookSignature;
use Nvade\Numerosis\Tests\TestCase;

/**
 * Covers the payment_method.attached handler added to fix the iDEAL
 * PaymentMethod-attach crash — see .claude/plans/archive/ideal-checkout-webhook-fix.md.
 *
 * A genuine end-to-end iDEAL round trip (bank redirect + Stripe's async
 * ideal -> sepa_debit conversion) cannot be automated here: Stripe's test
 * mode requires actually visiting a fake bank-authorization page to
 * complete it, which is why an earlier version of this fix was verified
 * against a card SetupIntent and still shipped with two real bugs — reading
 * $setupIntent->payment_method (never updated for iDEAL) and
 * Stripe\Service\SetupAttemptService::retrieve() (doesn't exist) — both
 * found only by inspecting tenants stuck in production. These tests instead
 * exercise the handler's real plumbing — matching by customer, listing a
 * customer's open pending checkouts, retrieving a SetupIntent by id,
 * resolving and finalizing against a PaymentMethod — using a real card
 * SetupIntent/PaymentMethod as a stand-in for the sepa_debit one a real
 * iDEAL conversion would produce.
 */
class WebhookControllerSetupIntentTest extends TestCase
{
    use CreatesCheckoutFixtures;
    use DisablesWebhookSignature;
    use RefreshDatabase;

    /**
     * @return array<string, mixed>
     */
    private function paymentMethodAttachedPayload(string $paymentMethodId, ?string $customerId, ?string $setupAttemptId): array
    {
        return [
            'id' => 'evt_'.$paymentMethodId,
            'type' => 'payment_method.attached',
            'data' => [
                'object' => array_filter([
                    'id' => $paymentMethodId,
                    'customer' => $customerId,
                    'sepa_debit' => $setupAttemptId !== null
                        ? ['generated_from' => ['setup_attempt' => $setupAttemptId]]
                        : null,
                ]),
            ],
        ];
    }

    public function test_it_ignores_a_payment_method_not_generated_from_a_setup_intent(): void
    {
        // No sepa_debit.generated_from.setup_attempt at all — a plain card
        // attach, or a sepa_debit set up directly rather than via iDEAL/
        // Bancontact conversion. The handler must return before making any
        // Stripe or database call for this, so made-up ids are fine.
        $response = $this->postJson(
            Config::string('numerosis.billing.webhook_path', 'billing/webhook'),
            $this->paymentMethodAttachedPayload('pm_not_ours', 'cus_not_ours', null),
        );

        $response->assertOk();
    }

    public function test_it_ignores_a_generated_payment_method_for_an_unknown_customer(): void
    {
        // Has the generated-from shape but the customer matches no
        // CentralUser — must not error, just acknowledge.
        $response = $this->postJson(
            Config::string('numerosis.billing.webhook_path', 'billing/webhook'),
            $this->paymentMethodAttachedPayload('pm_orphan', 'cus_no_such_customer', 'setatt_fake'),
        );

        $response->assertOk();
    }

    public function test_payment_method_attached_completes_a_pending_checkout(): void
    {
        $fake = Billing::fake();

        $this->createStarterPlan($this->starterPriceIdOrSkip());

        $user = CentralUser::factory()->create();

        $paymentMethod = $this->cardWithBillingAddress();
        $setupIntent = $this->confirmedSetupIntentFor($user, $paymentMethod->id);
        $customerId = $user->stripeIdOrFail();

        $this->reserve('webhook-pm-attached-test', $user, $setupIntent->id, [
            'payment_plan' => 'starter',
            'billing_cycle' => BillingCycle::Monthly,
        ]);

        $this->postJson(
            Config::string('numerosis.billing.webhook_path', 'billing/webhook'),
            $this->paymentMethodAttachedPayload($paymentMethod->id, $customerId, 'setatt_stand_in'),
        )->assertOk();

        $fake->assertTenantProvisioned('webhook-pm-attached-test');

        $pending = PendingTenantProvision::find('webhook-pm-attached-test');
        $this->assertNotNull($pending);
        $this->assertNotNull($pending->stripe_subscription_id);
    }

    /**
     * The race `.ai/rules/tenant-provisioning.md` documents, driven in its
     * worse order: the async webhook lands first, the customer's browser
     * comes back to the return route afterwards. Both funnel through
     * `SettleCheckout`, so `CheckoutCompleted` has to be observable exactly
     * once — a host counting conversions off it must not double-count.
     */
    public function test_the_webhook_then_redirect_race_dispatches_checkout_completed_once(): void
    {
        $fake = Billing::fake();

        $this->createStarterPlan($this->starterPriceIdOrSkip());

        $user = CentralUser::factory()->create();
        $this->actingAs($user);

        $paymentMethod = $this->cardWithBillingAddress();
        $setupIntent = $this->confirmedSetupIntentFor($user, $paymentMethod->id);
        $customerId = $user->stripeIdOrFail();

        $this->reserve('webhook-then-redirect-test', $user, $setupIntent->id, [
            'payment_plan' => 'starter',
            'billing_cycle' => BillingCycle::Monthly,
        ]);

        Event::fake([CheckoutCompleted::class]);

        $this->postJson(
            Config::string('numerosis.billing.webhook_path', 'billing/webhook'),
            $this->paymentMethodAttachedPayload($paymentMethod->id, $customerId, 'setatt_stand_in'),
        )->assertOk();

        // The customer's browser, arriving second. AssertPendingReservationIsFresh
        // sees the subscription id the webhook wrote and refuses, so this
        // never reaches SettleCheckout again.
        $this->get(route('checkout.subscription.return', ['setup_intent' => $setupIntent->id]))
            ->assertRedirect(route('tenants.mine'));

        Event::assertDispatchedTimes(CheckoutCompleted::class, 1);
        Event::assertDispatched(fn (CheckoutCompleted $e): bool => $e->domain === 'webhook-then-redirect-test'
            && $e->planId === 'starter');

        $fake->assertTenantProvisioned('webhook-then-redirect-test');
    }

    /**
     * The redirect route already finished this checkout (or a duplicate
     * webhook delivery arrived) — must not create a second subscription.
     */
    public function test_payment_method_attached_is_a_noop_once_already_completed(): void
    {
        $this->createStarterPlan($this->starterPriceIdOrSkip());

        $user = CentralUser::factory()->create();

        $paymentMethod = $this->cardWithBillingAddress();
        $setupIntent = $this->confirmedSetupIntentFor($user, $paymentMethod->id);
        $customerId = $user->stripeIdOrFail();

        $this->reserve('webhook-pm-attached-idempotent-test', $user, $setupIntent->id, [
            'payment_plan' => 'starter',
            'billing_cycle' => BillingCycle::Monthly,
            'stripe_subscription_id' => 'sub_already_completed',
        ]);

        $this->postJson(
            Config::string('numerosis.billing.webhook_path', 'billing/webhook'),
            $this->paymentMethodAttachedPayload($paymentMethod->id, $customerId, 'setatt_stand_in'),
        )->assertOk();

        $pending = PendingTenantProvision::find('webhook-pm-attached-idempotent-test');
        $this->assertSame('sub_already_completed', $pending?->stripe_subscription_id);

        $subscriptions = Cashier::stripe()->subscriptions->all(['customer' => $customerId]);
        $this->assertCount(0, $subscriptions->data);
    }
}
