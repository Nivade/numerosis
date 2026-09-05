<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Actions\Billing\Subscriptions;

use Illuminate\Validation\ValidationException;
use Lorisleiva\Actions\Concerns\AsAction;
use Nvade\Numerosis\Contracts\Billing\PlanPolicy;
use Nvade\Numerosis\Contracts\Subscribable;
use Nvade\Numerosis\Models\Central\PaymentPlan;
use Nvade\Numerosis\Models\Central\Subscription;

class SwapSubscriptionPlan
{
    use AsAction;

    public function __construct(private readonly PlanPolicy $planPolicy) {}

    public function handle(
        Subscribable $for,
        Subscription $subscription,
        PaymentPlan $from,
        PaymentPlan $to,
        string $priceId,
    ): Subscription {
        if (! $this->planPolicy->canSwap($for, $from, $to)) {
            throw ValidationException::withMessages([
                'plan' => 'You are not eligible to switch to this plan.',
            ]);
        }

        $subscription->swapAndInvoice($priceId);
        $subscription->update(['payment_plan_id' => $to->id]);

        // No `Events\Billing\SubscriptionPlanChanged` here: the swap produces
        // a `customer.subscription.updated` webhook, which
        // `WebhookController` already dispatches it from.
        return $subscription;
    }
}
