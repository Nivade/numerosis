<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Actions\Billing\Checkout;

use Illuminate\Support\Facades\Log;
use Laravel\Cashier\Cashier;
use Laravel\Cashier\Exceptions\IncompletePayment;
use Lorisleiva\Actions\Concerns\AsAction;
use Nvade\Numerosis\Actions\Billing\Promotions\RecordAppliedPromotion;
use Nvade\Numerosis\Actions\Billing\Promotions\ValidatePromotionCode;
use Nvade\Numerosis\Contracts\Billing\BillableResolver;
use Nvade\Numerosis\Contracts\Billing\BillableUser;
use Nvade\Numerosis\Contracts\Billing\PaymentPlanRepository;
use Nvade\Numerosis\Contracts\Billing\Plan;
use Nvade\Numerosis\Contracts\Billing\TrialResolver;
use Nvade\Numerosis\Data\Billing\PromotionData;
use Nvade\Numerosis\Enums\Billing\BillingCycle;
use Nvade\Numerosis\Exceptions\Billing\BillingCycleRequired;
use Nvade\Numerosis\Exceptions\Billing\PromotionCodeUnavailable;
use Nvade\Numerosis\Exceptions\Billing\StripePriceNotConfigured;
use Nvade\Numerosis\Exceptions\Billing\UnsupportedBillable;
use Nvade\Numerosis\Models\Central\Subscription;
use Nvade\Numerosis\Models\Central\TenantProvision;
use RuntimeException;
use Stripe\Exception\ApiErrorException;

/**
 * Creates the Stripe subscription for a checkout. Takes an explicit billable
 * for callers with no session of their own, the Stripe webhook in particular;
 * everyone else resolves it from the request. On an incomplete payment the
 * subscription id is recorded before the exception propagates, so a replay
 * cannot create a second subscription while the challenge is pending.
 *
 * @throws IncompletePayment
 * @throws ApiErrorException the Stripe call underneath `newSubscription()->create()`
 *
 * @method static Subscription run(TenantProvision $pending, string $paymentMethodId, ?BillableUser $billable = null)
 */
class CreateInlineSubscription
{
    use AsAction;

    public function __construct(
        private readonly BillableResolver $billables,
        private readonly PaymentPlanRepository $plans,
        private readonly TrialResolver $trials,
    ) {}

    public function handle(TenantProvision $pending, string $paymentMethodId, ?BillableUser $billable = null): Subscription
    {
        $plan = $this->plans->findBySlugOrFail((string) $pending->payment_plan);

        $billingCycle = $pending->billing_cycle;

        throw_if($billingCycle === null, BillingCycleRequired::class, 'A billing cycle is required to create a subscription.');

        $priceId = $plan->priceId($billingCycle);

        if ($priceId === null || $priceId === '') {
            throw new StripePriceNotConfigured("Stripe Price ID not found for plan: {$pending->payment_plan}");
        }

        $billable ??= $this->billables->resolve();

        throw_unless($billable instanceof BillableUser, UnsupportedBillable::class, 'Billable must be a central user to create an inline subscription.');

        // Checked here instead of on the object Cashier returns. The same
        // mismatch is knowable before the charge, and after it a throw leaves
        // the customer subscribed in Stripe with nothing local recording it.
        throw_unless(is_a(Cashier::$subscriptionModel, Subscription::class, true), RuntimeException::class, 'Cashier is configured with a subscription model that is not '.Subscription::class.'; check numerosis.billing.models.subscription.');

        $stripeSubscription = $billable->newSubscription('default', $priceId)
            ->withMetadata(['slug' => $pending->slug]);

        $additionalPrices = $plan->metadata()['additional_prices'] ?? [];

        if (is_array($additionalPrices)) {
            foreach ($additionalPrices as $additionalPriceId) {
                $stripeSubscription->price($additionalPriceId);
            }
        }

        $trialDays = $this->trials->daysFor($plan, null);
        if ($trialDays !== null && $trialDays > 0) {
            $stripeSubscription->trialDays($trialDays);
        }

        // Carried into the builder instead of applied to the subscription
        // afterwards, so the first invoice is the discounted one; also
        // re-validated since a redemption limit can have been reached since typed.
        $promotion = $this->promotionFor($pending, $billable, $plan, $billingCycle);

        if ($promotion instanceof PromotionData) {
            $stripeSubscription->withPromotionCode($promotion->promotion_code_id);
        }

        try {
            $subscription = $stripeSubscription->create($paymentMethodId);
        } catch (IncompletePayment $e) {
            $created = $billable->subscriptions()
                ->where('type', 'default')
                ->latest('id')
                ->first();

            if ($created !== null) {
                $pending->update(['stripe_subscription_id' => $created->stripe_id]);
            }

            throw $e;
        }

        assert($subscription instanceof Subscription);

        $pending->update(['stripe_subscription_id' => $subscription->stripe_id]);

        if ($promotion instanceof PromotionData) {
            RecordAppliedPromotion::run($promotion, $subscription->stripe_id, $pending->global_id);
        }

        return $subscription;
    }

    /**
     * A code that no longer validates is dropped instead of raised as an
     * error. The customer already entered a card for a subscription they
     * asked for, and refusing the whole charge over a discount loses the sale.
     */
    private function promotionFor(
        TenantProvision $pending,
        BillableUser $billable,
        Plan $plan,
        BillingCycle $billingCycle,
    ): ?PromotionData {
        $code = $pending->promotion_code;

        if ($code === null || $code === '') {
            return null;
        }

        try {
            return ValidatePromotionCode::run($code, $billable, $plan, $billingCycle);
        } catch (PromotionCodeUnavailable $e) {
            Log::info('Promotion code dropped at subscription creation', [
                'slug' => $pending->slug,
                'reason' => $e->reason,
            ]);

            $pending->update(['promotion_code' => null]);

            return null;
        }
    }
}
