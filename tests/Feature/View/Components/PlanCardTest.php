<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Tests\Feature\View\Components;

use App\Models\Central\PaymentPlan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestView;
use Nvade\Numerosis\Enums\BillingCycle;
use Nvade\Numerosis\Services\Billing\BillingService;
use Nvade\Numerosis\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Replaces PlanComponentTest, which covered the class-based
 * Nvade\Numerosis\View\Components\Card\Plan. That component is gone; the same markup is now
 * the anonymous `x-billing.plan-card`, which takes the *formatted* price as a
 * prop rather than deriving it from the plan.
 *
 * That difference is the whole reason these assertions matter. The component
 * decided "free" with `@if($price > 0)` against a string like "€ 0,00", and PHP
 * compares a non-numeric string to an int as strings — every currency symbol
 * sorts above "0", so the condition was always true and the free branch was
 * unreachable.
 */
class PlanCardTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @param  'display'|'selectable'  $type
     */
    private function render(PaymentPlan $plan, BillingCycle $cycle, string $type): TestView
    {
        $price = resolve(BillingService::class)->formatAmount($plan->getPrice($cycle));

        return $this->blade(
            '<x-numerosis::billing.plan-card :plan="$plan" :billing-cycle="$cycle" :price="$price" :type="$type" />',
            ['plan' => $plan, 'cycle' => $cycle, 'price' => $price, 'type' => $type],
        );
    }

    /**
     * @return list<array{'display'|'selectable'}>
     */
    public static function cardTypes(): array
    {
        return [['display'], ['selectable']];
    }

    #[DataProvider('cardTypes')]
    public function test_it_displays_the_price_for_a_paid_plan(string $type): void
    {
        $plan = PaymentPlan::factory()->create([
            'monthly_price' => 1000,
            'yearly_price' => 10000,
        ]);

        // Asserted against formatAmount()'s own output, not a literal
        // '10,00': the separator is decided by cashier.currency /
        // cashier.currency_locale, which are host config (saas-m ran
        // EUR/nl, this harness runs the Cashier defaults). The claim here
        // is "a paid plan renders its price rather than 'Free'", and that
        // is true in every locale.
        $price = resolve(BillingService::class)->formatAmount($plan->getPrice(BillingCycle::Monthly));

        $this->render($plan, BillingCycle::Monthly, $type)
            ->assertSee($price)
            ->assertDontSee('Free');
    }

    #[DataProvider('cardTypes')]
    public function test_it_displays_free_for_a_zero_price_plan(string $type): void
    {
        $plan = PaymentPlan::factory()->create([
            'monthly_price' => 0,
            'yearly_price' => 0,
        ]);

        $this->render($plan, BillingCycle::Monthly, $type)
            ->assertSee('Free')
            ->assertDontSee('0,00');
    }
}
