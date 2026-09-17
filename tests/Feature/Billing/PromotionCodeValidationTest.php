<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Tests\Feature\Billing;

use App\Models\Central\CentralUser;
use App\Models\Central\PaymentPlan;
use App\Models\Central\Subscription;
use App\Models\Central\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Nvade\Numerosis\Actions\Billing\Promotions\ValidatePromotionCode;
use Nvade\Numerosis\Enums\Billing\BillingCycle;
use Nvade\Numerosis\Exceptions\Billing\PromotionCodeUnavailable;
use Nvade\Numerosis\Models\Central\CentralUser as BaseCentralUser;
use Nvade\Numerosis\Models\Central\PaymentPlan as BasePaymentPlan;
use Nvade\Numerosis\Testing\FakesStripe;
use Nvade\Numerosis\Testing\FakeStripeHttpClient;
use Nvade\Numerosis\Tests\TestCase;

/**
 * One message per failure mode. "Invalid code" for all of them is what sends a
 * customer holding a real but exhausted code to support.
 */
class PromotionCodeValidationTest extends TestCase
{
    use FakesStripe;
    use RefreshDatabase;

    public function test_a_usable_code_comes_back_with_what_stripe_said(): void
    {
        $stripe = $this->fakeStripe();
        $stripe->addPromotionCode('LAUNCH25');

        $promotion = ValidatePromotionCode::run('LAUNCH25', $this->billable($stripe), $this->plan(), BillingCycle::Monthly);

        $this->assertSame('LAUNCH25', $promotion->code);
        $this->assertSame(25, $promotion->percent_off);
        $this->assertSame('25% off', $promotion->label());
    }

    public function test_an_unknown_code_says_so(): void
    {
        $stripe = $this->fakeStripe();

        $this->assertReason('unknown', fn () => ValidatePromotionCode::run('NOPE', $this->billable($stripe)));
    }

    public function test_an_inactive_code_reads_as_expired_rather_than_unknown(): void
    {
        $stripe = $this->fakeStripe();
        $stripe->addPromotionCode('OLD', ['active' => false]);

        $this->assertReason('expired', fn () => ValidatePromotionCode::run('OLD', $this->billable($stripe)));
    }

    public function test_a_code_past_its_expiry_reads_as_expired(): void
    {
        $stripe = $this->fakeStripe();
        $stripe->addPromotionCode('LAPSED', ['expires_at' => now()->subDay()->getTimestamp()]);

        $this->assertReason('expired', fn () => ValidatePromotionCode::run('LAPSED', $this->billable($stripe)));
    }

    public function test_a_code_at_its_redemption_limit_reads_as_exhausted(): void
    {
        $stripe = $this->fakeStripe();
        $stripe->addPromotionCode('FULL', ['max_redemptions' => 10, 'times_redeemed' => 10]);

        $this->assertReason('exhausted', fn () => ValidatePromotionCode::run('FULL', $this->billable($stripe)));
    }

    public function test_a_code_pinned_to_another_customer_is_refused(): void
    {
        $stripe = $this->fakeStripe();
        $stripe->addPromotionCode('THEIRS', ['customer' => 'cus_somebody_else']);

        $this->assertReason('other_customer', fn () => ValidatePromotionCode::run('THEIRS', $this->billable($stripe)));
    }

    public function test_a_first_purchase_code_is_refused_once_a_subscription_exists(): void
    {
        $stripe = $this->fakeStripe();
        $stripe->addPromotionCode('NEWBIE', ['restrictions' => [
            'first_time_transaction' => true,
            'minimum_amount' => null,
            'minimum_amount_currency' => null,
        ]]);

        $billable = $this->billable($stripe);

        Tenant::unsetEventDispatcher();
        Subscription::factory()->create([
            'subscribable_id' => $billable->getKey(),
            'subscribable_type' => $billable::class,
        ]);

        $this->assertReason('first_purchase_only', fn () => ValidatePromotionCode::run('NEWBIE', $billable));
    }

    public function test_a_code_below_its_minimum_order_says_the_minimum(): void
    {
        $stripe = $this->fakeStripe();
        $stripe->addPromotionCode('BIGSPEND', ['restrictions' => [
            'first_time_transaction' => false,
            'minimum_amount' => 50_000,
            'minimum_amount_currency' => 'eur',
        ]]);

        $this->assertReason(
            'minimum_amount',
            fn () => ValidatePromotionCode::run('BIGSPEND', $this->billable($stripe), $this->plan(), BillingCycle::Monthly),
        );
    }

    public function test_a_product_restricted_code_is_refused_for_another_plan(): void
    {
        $stripe = $this->fakeStripe();
        $stripe->addPromotionCode('ENTERPRISE', [], ['applies_to' => ['products' => ['prod_enterprise']]]);
        $stripe->priceProducts['price_monthly'] = 'prod_starter';

        $this->assertReason(
            'product_restricted',
            fn () => ValidatePromotionCode::run('ENTERPRISE', $this->billable($stripe), $this->plan(), BillingCycle::Monthly),
        );
    }

    public function test_a_product_restricted_code_applies_to_its_own_product(): void
    {
        $stripe = $this->fakeStripe();
        $stripe->addPromotionCode('STARTER', [], ['applies_to' => ['products' => ['prod_starter']]]);
        $stripe->priceProducts['price_monthly'] = 'prod_starter';

        $promotion = ValidatePromotionCode::run('STARTER', $this->billable($stripe), $this->plan(), BillingCycle::Monthly);

        $this->assertSame('STARTER', $promotion->code);
    }

    private function assertReason(string $reason, callable $call): void
    {
        try {
            $call();
        } catch (PromotionCodeUnavailable $e) {
            $this->assertSame($reason, $e->reason);
            $this->assertNotSame('', $e->getMessage());

            return;
        }

        $this->fail("Expected the code to be refused as [{$reason}].");
    }

    private function billable(FakeStripeHttpClient $stripe): BaseCentralUser
    {
        $user = CentralUser::factory()->create(['stripe_id' => 'cus_mine']);

        $stripe->requests = [];

        return $user;
    }

    private function plan(): BasePaymentPlan
    {
        return PaymentPlan::factory()->create([
            'monthly_id' => 'price_monthly',
            'monthly_price' => 2_500,
        ]);
    }
}
