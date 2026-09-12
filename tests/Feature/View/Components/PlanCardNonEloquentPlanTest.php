<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Tests\Feature\View\Components;

use Illuminate\Support\Collection;
use Nvade\Numerosis\Contracts\Billing\PaymentPlanRepository;
use Nvade\Numerosis\Contracts\Billing\Plan;
use Nvade\Numerosis\Data\Billing\PlanFeature;
use Nvade\Numerosis\Enums\Billing\BillingCycle;
use Nvade\Numerosis\Services\Billing\BillingService;
use Nvade\Numerosis\Tests\TestCase;

/**
 * The seam `PaymentPlanRepository`/`Plan` exists in signature only if the
 * views bound to it still reach for a concrete `PaymentPlan`. Binding a
 * plain, non-Eloquent implementation and rendering through it is the only
 * thing that proves the seam is real.
 */
class PlanCardNonEloquentPlanTest extends TestCase
{
    private function stubPlan(): Plan
    {
        return new class implements Plan
        {
            public string $description = 'A stub plan with no database row.';

            public function slug(): string
            {
                return 'stub-plan';
            }

            public function name(): string
            {
                return 'Stub Plan';
            }

            public function priceId(BillingCycle $cycle): string
            {
                return 'price_stub';
            }

            public function price(BillingCycle $cycle): int
            {
                return 1500;
            }

            public function trialDays(): int
            {
                return 7;
            }

            public function metadata(): array
            {
                return [];
            }
        };
    }

    private function bindStubRepository(Plan $plan): void
    {
        app()->bind(PaymentPlanRepository::class, fn (): PaymentPlanRepository => new class($plan) implements PaymentPlanRepository
        {
            public function __construct(private readonly Plan $plan) {}

            public function findBySlug(string $slug): Plan
            {
                return $this->plan;
            }

            public function findBySlugOrFail(string $slug): Plan
            {
                return $this->plan;
            }

            public function findAnyBySlug(string $slug): Plan
            {
                return $this->plan;
            }

            public function findByPriceId(string $priceId): Plan
            {
                return $this->plan;
            }

            public function available(): Collection
            {
                return new Collection([$this->plan]);
            }

            public function mostPopularSlug(): string
            {
                return $this->plan->slug();
            }

            public function featuresFor(Plan $plan): Collection
            {
                return new Collection([
                    new PlanFeature(
                        slug: 'widgets',
                        name: 'Unlimited widgets',
                        description: 'As many widgets as you like.',
                        available: true,
                    ),
                ]);
            }
        });
    }

    public function test_plan_card_renders_against_a_non_eloquent_plan(): void
    {
        $plan = $this->stubPlan();
        $this->bindStubRepository($plan);

        $price = resolve(BillingService::class)->formatAmount($plan->price(BillingCycle::Monthly) ?? 0);

        $this->blade(
            '<x-numerosis::billing.plan-card :plan="$plan" :billing-cycle="$cycle" :price="$price" type="selectable" />',
            ['plan' => $plan, 'cycle' => BillingCycle::Monthly, 'price' => $price],
        )
            ->assertSee('Stub Plan')
            ->assertSee($price)
            ->assertSee('Unlimited widgets')
            ->assertSee('Most Popular');
    }

    public function test_order_summary_renders_against_a_non_eloquent_plan(): void
    {
        $plan = $this->stubPlan();
        $this->bindStubRepository($plan);

        $price = resolve(BillingService::class)->formatAmount($plan->price(BillingCycle::Monthly) ?? 0);

        $this->blade(
            '<x-numerosis::billing.order-summary :plan="$plan" :billing-cycle="$cycle" domain="acme" />',
            ['plan' => $plan, 'cycle' => BillingCycle::Monthly],
        )
            ->assertSee('Stub Plan')
            ->assertSee('7 days');
    }
}
