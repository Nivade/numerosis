<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Tests\Feature\Actions\Billing\Subscriptions;

use App\Models\Central\PaymentPlan;
use App\Models\Central\Subscription;
use App\Models\Central\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use Nvade\Numerosis\Actions\Billing\Subscriptions\SwapSubscriptionPlan;
use Nvade\Numerosis\Contracts\Billing\Plan;
use Nvade\Numerosis\Contracts\Billing\PlanPolicy;
use Nvade\Numerosis\Contracts\Subscribable;
use Nvade\Numerosis\Events\Billing\SubscriptionPlanChanged;
use Nvade\Numerosis\Exceptions\Billing\PaymentPlanNotFound;
use Nvade\Numerosis\Models\Central\PaymentPlan as BasePaymentPlan;
use Nvade\Numerosis\Models\Central\Tenant as BaseTenant;
use Nvade\Numerosis\Tests\Support\SubscriptionWithoutStripe;
use Nvade\Numerosis\Tests\TestCase;

class SwapSubscriptionPlanTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_records_the_new_plan_against_the_subscription(): void
    {
        $tenant = Tenant::factory()->create();
        $from = PaymentPlan::factory()->create(['monthly_price' => 1000]);
        $to = PaymentPlan::factory()->create(['monthly_price' => 2000]);
        $subscription = $this->subscriptionWithoutStripe($tenant, $from, 'price_old');

        SwapSubscriptionPlan::run($tenant, $subscription, $from, $to, 'price_new');

        // Re-read through the real model, not `$subscription->refresh()`:
        // SubscriptionWithoutStripe makes Eloquent guess a foreign key off its
        // basename, which breaks any relation reload.
        $this->assertSame($to->id, Subscription::where('stripe_id', $subscription->stripe_id)->value('payment_plan_id'));
    }

    public function test_it_refuses_a_swap_the_plan_policy_rejects(): void
    {
        app()->bind(PlanPolicy::class, fn (): PlanPolicy => new class implements PlanPolicy
        {
            public function assertEligible(Subscribable $for, Plan $plan): void {}

            public function canSwap(Subscribable $for, Plan $from, Plan $to): bool
            {
                return false;
            }

            public function hasSeatForNewInvitation(Subscribable $for): bool
            {
                return true;
            }

            public function hasSeatForNewMember(Subscribable $for): bool
            {
                return true;
            }
        });

        $tenant = Tenant::factory()->create();
        $from = PaymentPlan::factory()->create(['monthly_price' => 1000]);
        $to = PaymentPlan::factory()->create(['monthly_price' => 2000]);
        $subscription = $this->subscriptionWithoutStripe($tenant, $from, 'price_old');

        $this->expectException(ValidationException::class);

        SwapSubscriptionPlan::run($tenant, $subscription, $from, $to, 'price_new');
    }

    /** An unresolvable slug used to write a null `payment_plan_id` and report success. */
    public function test_it_refuses_a_plan_that_no_longer_resolves(): void
    {
        $tenant = Tenant::factory()->create();
        $from = PaymentPlan::factory()->create(['monthly_price' => 1000]);
        $to = PaymentPlan::factory()->create(['monthly_price' => 2000]);
        $subscription = $this->subscriptionWithoutStripe($tenant, $from, 'price_old');

        $to->delete();

        $this->expectException(PaymentPlanNotFound::class);

        try {
            SwapSubscriptionPlan::run($tenant, $subscription, $from, $to, 'price_new');
        } finally {
            $this->assertSame($from->id, Subscription::where('stripe_id', $subscription->stripe_id)->value('payment_plan_id'));
        }
    }

    /**
     * `SubscriptionPlanChanged` belongs to the `customer.subscription.updated`
     * webhook, which sees this swap too. Dispatching here as well would fire
     * it twice for one change — see
     * `WebhookControllerLifecycleTest::test_subscription_updated_to_a_new_price_dispatches_a_plan_change`.
     */
    public function test_it_does_not_dispatch_the_plan_change_event_itself(): void
    {
        Event::fake([SubscriptionPlanChanged::class]);

        $tenant = Tenant::factory()->create();
        $from = PaymentPlan::factory()->create(['monthly_price' => 1000]);
        $to = PaymentPlan::factory()->create(['monthly_price' => 2000]);
        $subscription = $this->subscriptionWithoutStripe($tenant, $from, 'price_old');

        SwapSubscriptionPlan::run($tenant, $subscription, $from, $to, 'price_new');

        Event::assertNotDispatched(SubscriptionPlanChanged::class);
    }

    /**
     * `SwapSubscriptionPlan` still runs its own `update()` against this row.
     */
    private function subscriptionWithoutStripe(BaseTenant $tenant, BasePaymentPlan $plan, string $stripePrice): Subscription
    {
        /** @var array<string, mixed> $attributes */
        $attributes = Subscription::factory()->for($tenant, 'subscribable')->raw([
            'payment_plan_id' => $plan->id,
            'stripe_price' => $stripePrice,
        ]);

        $subscription = new SubscriptionWithoutStripe;

        $subscription->forceFill($attributes)->save();

        return $subscription;
    }
}
