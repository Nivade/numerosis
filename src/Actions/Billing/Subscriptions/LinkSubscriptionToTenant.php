<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Actions\Billing\Subscriptions;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Lorisleiva\Actions\Concerns\AsAction;
use Nvade\Numerosis\Contracts\Billing\SubscriptionRepository;
use Nvade\Numerosis\Data\Billing\StripeSubscriptionData;
use Nvade\Numerosis\Data\Billing\SubscriptionData;
use Nvade\Numerosis\Data\Tenancy\TenantProvisionData;
use Nvade\Numerosis\Models\Central\PaymentPlan;
use Nvade\Numerosis\Models\Central\Tenant;
use Nvade\Numerosis\Support\Numerosis;

// See .claude/rules/tenant-provisioning.md.
class LinkSubscriptionToTenant
{
    use AsAction;

    public function __construct(private readonly SubscriptionRepository $subscriptions) {}

    public function handle(TenantProvisionData $data, StripeSubscriptionData $stripeSubscription, Tenant $tenant): void
    {
        Cache::lock("reconcile-subscription:{$stripeSubscription->id}", 10)->block(5, function () use (
            $data,
            $stripeSubscription,
            $tenant,
        ) {
            $tenant->update(['stripe_id' => $data->stripeCustomerId]);

            $subscription = $this->subscriptions->findByStripeId($stripeSubscription->id);

            if (! $subscription) {
                $planId = Numerosis::model(PaymentPlan::class)::firstWhere('slug', $data->registration->payment_plan)?->id;

                $subscription = $this->subscriptions->record(new SubscriptionData(
                    user_id: $data->centralUserId,
                    payment_plan_id: $planId !== null ? (string) $planId : null,
                    stripe_id: $stripeSubscription->id,
                    stripe_status: $stripeSubscription->status,
                    subscribable_id: $tenant->id,
                    subscribable_type: Numerosis::model(Tenant::class),
                    stripe_price: $stripeSubscription->priceId,
                    quantity: $stripeSubscription->quantity,
                    trial_ends_at: $stripeSubscription->trialEndsAt,
                    items: $stripeSubscription->items,
                ));

                Log::info('Subscription created manually in LinkSubscriptionToTenant', [
                    'tenant_id' => $tenant->id,
                    'subscription_id' => $subscription->id,
                ]);
            } else {
                $subscription->update([
                    'subscribable_id' => $tenant->id,
                    'subscribable_type' => Numerosis::model(Tenant::class),
                ]);

                Log::info('Subscription transferred to tenant', [
                    'tenant_id' => $tenant->id,
                    'subscription_id' => $subscription->id,
                ]);
            }
        });
    }
}
