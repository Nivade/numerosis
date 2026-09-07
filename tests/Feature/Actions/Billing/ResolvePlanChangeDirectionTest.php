<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Tests\Feature\Actions\Billing;

use App\Models\Central\PaymentPlan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Nvade\Numerosis\Actions\Billing\ResolvePlanChangeDirection;
use Nvade\Numerosis\Enums\Billing\PlanChangeDirection;
use Nvade\Numerosis\Tests\TestCase;

/**
 * Extracted from `WebhookController::planChangeDirection()`, which was only
 * reachable through a Stripe webhook POST — so the cycle-matching rule and the
 * optimistic fallback had no test of their own.
 */
class ResolvePlanChangeDirectionTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_cheaper_price_in_the_same_cycle_is_a_downgrade(): void
    {
        $this->plans();

        $this->assertSame(
            PlanChangeDirection::Downgrade,
            ResolvePlanChangeDirection::run('price_pro_monthly', 'price_starter_monthly'),
        );
    }

    public function test_a_dearer_price_in_the_same_cycle_is_an_upgrade(): void
    {
        $this->plans();

        $this->assertSame(
            PlanChangeDirection::Upgrade,
            ResolvePlanChangeDirection::run('price_starter_monthly', 'price_pro_monthly'),
        );
    }

    /**
     * The trap the cycle lookup exists for: a yearly starter costs more than a
     * monthly pro, so comparing across cycles reports the wrong direction.
     */
    public function test_the_comparison_happens_inside_the_new_prices_cycle(): void
    {
        $this->plans();

        $this->assertSame(
            PlanChangeDirection::Downgrade,
            ResolvePlanChangeDirection::run('price_pro_yearly', 'price_starter_yearly'),
        );
    }

    public function test_an_unknown_price_falls_back_to_upgrade(): void
    {
        $this->plans();

        $this->assertSame(
            PlanChangeDirection::Upgrade,
            ResolvePlanChangeDirection::run('price_not_configured', 'price_starter_monthly'),
        );
    }

    private function plans(): void
    {
        PaymentPlan::factory()->create([
            'slug' => 'starter',
            'monthly_id' => 'price_starter_monthly',
            'yearly_id' => 'price_starter_yearly',
            'monthly_price' => 1000,
            'yearly_price' => 10000,
            'available' => true,
        ]);

        PaymentPlan::factory()->create([
            'slug' => 'pro',
            'monthly_id' => 'price_pro_monthly',
            'yearly_id' => 'price_pro_yearly',
            'monthly_price' => 5000,
            'yearly_price' => 50000,
            'available' => true,
        ]);
    }
}
