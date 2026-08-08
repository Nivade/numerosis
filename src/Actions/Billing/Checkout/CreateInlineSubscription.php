<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Actions\Billing\Checkout;

use Laravel\Cashier\Exceptions\IncompletePayment;
use Laravel\Cashier\Subscription;
use Lorisleiva\Actions\Concerns\AsAction;
use Nvade\Numerosis\Contracts\Billing\BillableResolver;
use Nvade\Numerosis\Contracts\Billing\PaymentPlanRepository;
use Nvade\Numerosis\Contracts\Billing\TrialResolver;
use Nvade\Numerosis\Exceptions\Billing\BillingCycleRequired;
use Nvade\Numerosis\Exceptions\Billing\PaymentPlanNotFound;
use Nvade\Numerosis\Exceptions\Billing\StripePriceNotConfigured;
use Nvade\Numerosis\Exceptions\Billing\UnsupportedBillable;
use Nvade\Numerosis\Models\Central\CentralUser;
use Nvade\Numerosis\Models\Central\PendingTenantProvision;

/**
 * See .claude/rules/billing-checkout.md.
 *
 * @throws IncompletePayment
 *
 * @method static Subscription run(PendingTenantProvision $pending, string $paymentMethodId, ?CentralUser $billable = null)
 */
class CreateInlineSubscription
{
    use AsAction;

    public function __construct(
        private readonly BillableResolver $billables,
        private readonly PaymentPlanRepository $plans,
        private readonly TrialResolver $trials,
    ) {}

    public function handle(PendingTenantProvision $pending, string $paymentMethodId, ?CentralUser $billable = null): Subscription
    {
        $plan = $this->plans->findBySlug((string) $pending->payment_plan);

        if (! $plan) {
            throw new PaymentPlanNotFound("Payment plan not found: {$pending->payment_plan}");
        }

        $billingCycle = $pending->billing_cycle;

        throw_if($billingCycle === null, BillingCycleRequired::class, 'A billing cycle is required to create a subscription.');

        $priceId = $plan->priceId($billingCycle);

        if (! $priceId) {
            throw new StripePriceNotConfigured("Stripe Price ID not found for plan: {$pending->payment_plan}");
        }

        $billable ??= $this->billables->resolve();

        throw_unless($billable instanceof CentralUser, UnsupportedBillable::class, 'Billable must be a CentralUser to create an inline subscription.');

        $stripeSubscription = $billable->newSubscription('default', $priceId)
            ->withMetadata(['domain' => $pending->domain]);

        // See .claude/rules/static-analysis.md re: treatPhpDocTypesAsCertain.
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

        try {
            $subscription = $stripeSubscription->create($paymentMethodId);
        } catch (IncompletePayment $e) {
            $created = $billable->subscriptions()
                ->where('type', 'default')
                ->latest('id')
                ->first();

            if ($created) {
                $pending->update(['stripe_subscription_id' => $created->stripe_id]);
            }

            throw $e;
        }

        $pending->update(['stripe_subscription_id' => $subscription->stripe_id]);

        return $subscription;
    }
}
