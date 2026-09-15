<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Actions\Billing\Subscriptions;

use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Lorisleiva\Actions\Concerns\AsAction;
use Nvade\Numerosis\Contracts\Billing\PaymentPlanRepository;
use Nvade\Numerosis\Contracts\Billing\Plan;
use Nvade\Numerosis\Contracts\Billing\PlanPolicy;
use Nvade\Numerosis\Contracts\Subscribable;
use Nvade\Numerosis\Models\Central\PaymentPlan;
use Nvade\Numerosis\Models\Central\Subscription;

class SwapSubscriptionPlan
{
    use AsAction;

    public function __construct(
        private readonly PlanPolicy $planPolicy,
        private readonly PaymentPlanRepository $paymentPlans,
    ) {}

    public function handle(
        Subscribable $for,
        Subscription $subscription,
        Plan $from,
        Plan $to,
        string $priceId,
    ): Subscription {
        if (! $this->planPolicy->canSwap($for, $from, $to)) {
            throw ValidationException::withMessages([
                'plan' => 'You are not eligible to switch to this plan.',
            ]);
        }

        $target = $this->paymentPlans->findBySlugOrFail($to->slug());

        if (! $target instanceof PaymentPlan) {
            throw new InvalidArgumentException("Payment plan is not stored locally: {$to->slug()}");
        }

        $subscription->swapAndInvoice($priceId);
        $subscription->update(['payment_plan_id' => $target->getKey()]);

        // No `Events\Billing\SubscriptionPlanChanged` here: the swap produces
        // a `customer.subscription.updated` webhook, which
        // `WebhookController` already dispatches it from.
        return $subscription;
    }
}
