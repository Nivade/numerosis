<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Tests\Feature\Billing;

use App\Models\Central\PaymentPlan;
use App\Models\Central\Subscription;
use App\Models\Central\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Nvade\Numerosis\Actions\Billing\Promotions\AcceptRetentionOffer;
use Nvade\Numerosis\Actions\Billing\Promotions\ResolveRetentionOffer;
use Nvade\Numerosis\Actions\Queries\GetTenantDiscount;
use Nvade\Numerosis\Data\Billing\PromotionData;
use Nvade\Numerosis\Models\Central\AppliedPromotion;
use Nvade\Numerosis\Models\Central\Subscription as BaseSubscription;
use Nvade\Numerosis\Models\Central\Tenant as BaseTenant;
use Nvade\Numerosis\Testing\FakesStripe;
use Nvade\Numerosis\Tests\TestCase;

/**
 * A retention offer only exists when a host configured one and Stripe still
 * honours it: a button that fails when pressed is worse than no offer.
 */
class RetentionOfferTest extends TestCase
{
    use FakesStripe;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('numerosis.billing.sync.stripe_customer', false);
    }

    public function test_no_offer_is_resolved_when_none_is_configured(): void
    {
        $stripe = $this->fakeStripe();
        $stripe->addPromotionCode('STAY20');

        [$tenant] = $this->subscribedTenant();

        $this->assertNull(ResolveRetentionOffer::run($tenant));
    }

    public function test_a_configured_offer_is_resolved_with_what_stripe_said(): void
    {
        $stripe = $this->fakeStripe();
        $stripe->addPromotionCode('STAY20', [], ['percent_off' => 20, 'duration' => 'repeating', 'duration_in_months' => 3]);
        Config::set('numerosis.billing.promotions.retention_code', 'STAY20');

        [$tenant] = $this->subscribedTenant();

        $offer = ResolveRetentionOffer::run($tenant);

        $this->assertInstanceOf(PromotionData::class, $offer);
        $this->assertSame('20% off', $offer->label());
        $this->assertSame('for 3 month(s)', $offer->durationLabel());
    }

    public function test_a_retired_code_degrades_to_no_offer_rather_than_a_broken_one(): void
    {
        $stripe = $this->fakeStripe();
        $stripe->addPromotionCode('STAY20', ['active' => false]);
        Config::set('numerosis.billing.promotions.retention_code', 'STAY20');

        [$tenant] = $this->subscribedTenant();

        $this->assertNull(ResolveRetentionOffer::run($tenant));
    }

    public function test_a_tenant_without_a_subscription_is_offered_nothing(): void
    {
        $stripe = $this->fakeStripe();
        $stripe->addPromotionCode('STAY20');
        Config::set('numerosis.billing.promotions.retention_code', 'STAY20');

        Tenant::unsetEventDispatcher();
        $tenant = Tenant::factory()->create(['stripe_id' => 'cus_retention']);

        $this->assertNull(ResolveRetentionOffer::run($tenant));
    }

    public function test_accepting_applies_the_discount_and_records_it(): void
    {
        $stripe = $this->fakeStripe();
        $stripe->addPromotionCode('STAY20', [], ['percent_off' => 20]);
        Config::set('numerosis.billing.promotions.retention_code', 'STAY20');

        [$tenant, $subscription] = $this->subscribedTenant();

        $applied = AcceptRetentionOffer::run($tenant);

        $this->assertInstanceOf(PromotionData::class, $applied);

        $row = AppliedPromotion::query()->where('stripe_subscription_id', $subscription->stripe_id)->first();

        $this->assertNotNull($row);
        $this->assertSame((string) $tenant->getTenantKey(), $row->tenant_id);
        $this->assertSame(20, $row->percent_off);
    }

    /** A tenant already holding a discount is not offered a second one. */
    public function test_a_discounted_tenant_gets_no_further_offer(): void
    {
        $stripe = $this->fakeStripe();
        $stripe->addPromotionCode('STAY20');
        Config::set('numerosis.billing.promotions.retention_code', 'STAY20');

        [$tenant, $subscription] = $this->subscribedTenant();

        $stripe->addSubscriptionDiscount($subscription->stripe_id, [
            'id' => 'di_existing',
            'object' => 'discount',
            'source' => ['type' => 'coupon', 'coupon' => $stripe->couponPayload(['id' => 'coupon_existing', 'percent_off' => 10, 'duration' => 'forever'])],
            'promotion_code' => null,
            'end' => null,
        ]);

        $this->assertInstanceOf(PromotionData::class, GetTenantDiscount::run($tenant));
        $this->assertNull(ResolveRetentionOffer::run($tenant));
    }

    public function test_a_discount_stripe_no_longer_reports_disappears(): void
    {
        $stripe = $this->fakeStripe();

        [$tenant, $subscription] = $this->subscribedTenant();

        $stripe->addSubscriptionDiscount($subscription->stripe_id, [
            'id' => 'di_ending',
            'object' => 'discount',
            'source' => ['type' => 'coupon', 'coupon' => $stripe->couponPayload(['id' => 'coupon_ending', 'percent_off' => 10])],
            'promotion_code' => null,
            'end' => now()->addWeek()->getTimestamp(),
        ]);

        $discount = GetTenantDiscount::run($tenant);

        $this->assertInstanceOf(PromotionData::class, $discount);
        $this->assertSame(now()->addWeek()->toDateString(), $discount->expires_at?->toDateString());

        $stripe->addSubscriptionDiscount($subscription->stripe_id, []);

        $this->assertNull(GetTenantDiscount::run($tenant->refresh()));
    }

    /**
     * Nothing in this package sends `discounts` when it changes a
     * subscription, so a discount Stripe keeps across an update stays kept. The
     * full `swapAndInvoice()` path is a live-Stripe test; this pins the claim
     * that no local write clears it.
     */
    public function test_an_update_to_the_subscription_does_not_drop_the_discount(): void
    {
        $stripe = $this->fakeStripe();

        [$tenant, $subscription] = $this->subscribedTenant();

        $stripe->addSubscriptionDiscount($subscription->stripe_id, [
            'id' => 'di_kept',
            'object' => 'discount',
            'source' => ['type' => 'coupon', 'coupon' => $stripe->couponPayload(['id' => 'coupon_kept', 'percent_off' => 15])],
            'promotion_code' => null,
            'end' => null,
        ]);

        $subscription->updateStripeSubscription(['metadata' => ['swapped' => 'yes']]);

        $discount = GetTenantDiscount::run($tenant);

        $this->assertInstanceOf(PromotionData::class, $discount);
        $this->assertSame(15, $discount->percent_off);
    }

    /**
     * @return array{0: BaseTenant, 1: BaseSubscription}
     */
    private function subscribedTenant(): array
    {
        Tenant::unsetEventDispatcher();

        $tenant = Tenant::factory()->create(['stripe_id' => 'cus_retention']);
        $plan = PaymentPlan::factory()->create(['monthly_id' => 'price_monthly']);

        $subscription = Subscription::factory()->create([
            'subscribable_id' => $tenant->id,
            'payment_plan_id' => $plan->id,
            'stripe_price' => 'price_monthly',
        ]);

        return [$tenant->refresh(), $subscription];
    }
}
