<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Tests\Feature\Http\Controllers\Billing;

use App\Models\Central\CentralUser;
use App\Models\Central\PaymentPlan;
use App\Models\Central\PendingTenantProvision;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Laravel\Cashier\Cashier;
use Nvade\Numerosis\Enums\BillingCycle;
use Nvade\Numerosis\Facades\Billing;
use Nvade\Numerosis\Tests\TestCase;

/**
 * Covers the payment_method.attached handler added to fix the iDEAL
 * PaymentMethod-attach crash — see .claude/plans/ideal-checkout-webhook-fix.md.
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
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // VerifyWebhookSignature is bound at controller construction time,
        // so it has to be off before that happens.
        config(['cashier.webhook.secret' => null]);
    }

    /**
     * @return array{setupIntentId: string, paymentMethodId: string, customerId: string}
     */
    private function confirmedSetupIntent(CentralUser $user): array
    {
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

        return [
            'setupIntentId' => $setupIntent->id,
            'paymentMethodId' => $paymentMethod->id,
            'customerId' => $customer->id,
        ];
    }

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

        $priceId = Config::string('numerosis.billing.plans.0.monthly_id');

        if ($priceId === '') {
            $this->markTestSkipped('No Stripe test-mode price configured (STRIPE_STARTER_MONTHLY_PLAN).');
        }

        $user = CentralUser::factory()->create();

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

        [
            'setupIntentId' => $setupIntentId,
            'paymentMethodId' => $paymentMethodId,
            'customerId' => $customerId,
        ] = $this->confirmedSetupIntent($user);

        PendingTenantProvision::factory()->create([
            'domain' => 'webhook-pm-attached-test',
            'global_id' => $user->global_id,
            'payment_plan' => 'starter',
            'billing_cycle' => BillingCycle::Monthly,
            'stripe_setup_intent_id' => $setupIntentId,
        ]);

        $this->postJson(
            Config::string('numerosis.billing.webhook_path', 'billing/webhook'),
            $this->paymentMethodAttachedPayload($paymentMethodId, $customerId, 'setatt_stand_in'),
        )->assertOk();

        $fake->assertTenantProvisioned('webhook-pm-attached-test');

        $pending = PendingTenantProvision::find('webhook-pm-attached-test');
        $this->assertNotNull($pending);
        $this->assertNotNull($pending->stripe_subscription_id);
    }

    /**
     * The redirect route already finished this checkout (or a duplicate
     * webhook delivery arrived) — must not create a second subscription.
     */
    public function test_payment_method_attached_is_a_noop_once_already_completed(): void
    {
        $priceId = Config::string('numerosis.billing.plans.0.monthly_id');

        if ($priceId === '') {
            $this->markTestSkipped('No Stripe test-mode price configured (STRIPE_STARTER_MONTHLY_PLAN).');
        }

        $user = CentralUser::factory()->create();

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

        [
            'setupIntentId' => $setupIntentId,
            'paymentMethodId' => $paymentMethodId,
            'customerId' => $customerId,
        ] = $this->confirmedSetupIntent($user);

        PendingTenantProvision::factory()->create([
            'domain' => 'webhook-pm-attached-idempotent-test',
            'global_id' => $user->global_id,
            'payment_plan' => 'starter',
            'billing_cycle' => BillingCycle::Monthly,
            'stripe_setup_intent_id' => $setupIntentId,
            'stripe_subscription_id' => 'sub_already_completed',
        ]);

        $this->postJson(
            Config::string('numerosis.billing.webhook_path', 'billing/webhook'),
            $this->paymentMethodAttachedPayload($paymentMethodId, $customerId, 'setatt_stand_in'),
        )->assertOk();

        $pending = PendingTenantProvision::find('webhook-pm-attached-idempotent-test');
        $this->assertSame('sub_already_completed', $pending?->stripe_subscription_id);

        $subscriptions = Cashier::stripe()->subscriptions->all(['customer' => $customerId]);
        $this->assertCount(0, $subscriptions->data);
    }
}
