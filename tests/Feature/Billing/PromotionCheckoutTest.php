<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Tests\Feature\Billing;

use App\Models\Central\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Nvade\Numerosis\Actions\Billing\Checkout\CreateInlineSubscription;
use Nvade\Numerosis\Data\Billing\PromotionData;
use Nvade\Numerosis\Enums\Billing\BillingCycle;
use Nvade\Numerosis\Livewire\Billing\Checkout;
use Nvade\Numerosis\Models\Central\AppliedPromotion;
use Nvade\Numerosis\Models\Central\CentralUser as BaseCentralUser;
use Nvade\Numerosis\Models\Central\TenantProvision as BaseTenantProvision;
use Nvade\Numerosis\Testing\FakesStripe;
use Nvade\Numerosis\Testing\FakeStripeHttpClient;
use Nvade\Numerosis\Tests\Concerns\CreatesCheckoutFixtures;
use Nvade\Numerosis\Tests\TestCase;

/**
 * The discount has to reach the Stripe subscription at creation, not be applied
 * to it afterwards, or the first invoice is the undiscounted one.
 */
class PromotionCheckoutTest extends TestCase
{
    use CreatesCheckoutFixtures;
    use FakesStripe;
    use RefreshDatabase;

    public function test_a_valid_code_is_carried_into_subscription_creation_and_recorded(): void
    {
        $stripe = $this->fakeStripe();
        $stripe->addPromotionCode('LAUNCH25');

        [$user, $pending] = $this->reservationWithCode($stripe, 'LAUNCH25');

        $subscription = CreateInlineSubscription::run($pending, $this->cardWithBillingAddress()->id, $user);

        $this->assertSame(
            [$stripe->promotionCodes['LAUNCH25']['id']],
            $this->promotionCodesSentWith($stripe, $subscription->stripe_id),
        );

        $row = AppliedPromotion::query()->where('stripe_subscription_id', $subscription->stripe_id)->first();

        $this->assertNotNull($row);
        $this->assertSame('LAUNCH25', $row->code);
        $this->assertSame(25, $row->percent_off);
        $this->assertSame($user->global_id, $row->global_id);
        $this->assertSame(1, AppliedPromotion::query()->count());
    }

    /**
     * The code was usable when it was typed and is not when the card is
     * entered. Charging the plan without the discount is the lesser harm; both
     * refusing the sale and billing a discount Stripe rejects are worse.
     */
    public function test_a_code_exhausted_since_it_was_typed_is_dropped_rather_than_fatal(): void
    {
        $stripe = $this->fakeStripe();
        $stripe->addPromotionCode('GONE', ['max_redemptions' => 1, 'times_redeemed' => 1]);

        [$user, $pending] = $this->reservationWithCode($stripe, 'GONE');

        $subscription = CreateInlineSubscription::run($pending, $this->cardWithBillingAddress()->id, $user);

        $this->assertSame([], $this->promotionCodesSentWith($stripe, $subscription->stripe_id));
        $this->assertSame(0, AppliedPromotion::query()->count());
        $this->assertNull($pending->refresh()->promotion_code);
    }

    /**
     * Redemptions are Stripe's count, never a local one: a trial collects
     * nothing, and whether that consumes the code is Stripe's rule to apply.
     */
    public function test_a_code_on_a_trialling_subscription_is_carried_without_counting_a_redemption(): void
    {
        $stripe = $this->fakeStripe();
        $stripe->addPromotionCode('LAUNCH25');

        [$user, $pending] = $this->reservationWithCode($stripe, 'LAUNCH25', trialDays: 14);

        $subscription = CreateInlineSubscription::run($pending, $this->cardWithBillingAddress()->id, $user);

        $this->assertSame('trialing', $subscription->stripe_status);
        $this->assertSame(
            [$stripe->promotionCodes['LAUNCH25']['id']],
            $this->promotionCodesSentWith($stripe, $subscription->stripe_id),
        );
        $this->assertSame(0, $stripe->promotionCodes['LAUNCH25']['times_redeemed']);
    }

    public function test_no_row_is_written_when_no_code_was_applied(): void
    {
        $stripe = $this->fakeStripe();

        [$user, $pending] = $this->reservationWithCode($stripe, null);

        CreateInlineSubscription::run($pending, $this->cardWithBillingAddress()->id, $user);

        $this->assertSame(0, AppliedPromotion::query()->count());
    }

    public function test_the_checkout_component_applies_a_code_and_stores_it_on_the_reservation(): void
    {
        $stripe = $this->fakeStripe();
        $stripe->addPromotionCode('LAUNCH25');

        [, $pending] = $this->reservationWithCode($stripe, null);

        Livewire::test(Checkout::class, ['domain' => $pending->slug])
            ->set('promotionCode', 'LAUNCH25')
            ->call('applyPromotionCode')
            ->assertSet('promotionError', null)
            ->assertSet('appliedPromotion', fn (?PromotionData $applied): bool => $applied?->code === 'LAUNCH25')
            ->assertSee('25% off');

        $this->assertSame('LAUNCH25', $pending->refresh()->promotion_code);
    }

    /**
     * The wizard embeds this same component rather than owning a second code
     * field, which is what keeps the two entry points from drifting — the
     * asymmetry `StartCheckoutRequest` has already caused once.
     */
    public function test_the_embedded_and_standalone_checkouts_apply_a_code_identically(): void
    {
        $stripe = $this->fakeStripe();
        $stripe->addPromotionCode('LAUNCH25');

        [, $pending] = $this->reservationWithCode($stripe, null);

        foreach ([false, true] as $embedded) {
            Livewire::test(Checkout::class, [
                'domain' => $pending->slug,
                'embedded' => $embedded,
            ])
                ->set('promotionCode', 'LAUNCH25')
                ->call('applyPromotionCode')
                ->assertSet('appliedPromotion', fn (?PromotionData $applied): bool => $applied?->percent_off === 25);

            $this->assertSame('LAUNCH25', $pending->refresh()->promotion_code);
        }
    }

    public function test_a_refused_code_shows_its_own_reason_and_is_not_stored(): void
    {
        $stripe = $this->fakeStripe();
        $stripe->addPromotionCode('OLD', ['active' => false]);

        [, $pending] = $this->reservationWithCode($stripe, null);

        Livewire::test(Checkout::class, ['domain' => $pending->slug])
            ->set('promotionCode', 'OLD')
            ->call('applyPromotionCode')
            ->assertSet('appliedPromotion', null)
            ->assertSet('promotionError', __('numerosis::billing.promotion.expired'));

        $this->assertNull($pending->refresh()->promotion_code);
    }

    /** A `?promo=` link only pre-fills; Stripe still decides. */
    public function test_a_code_from_a_marketing_link_is_validated_rather_than_trusted(): void
    {
        $stripe = $this->fakeStripe();
        $stripe->addPromotionCode('OLD', ['active' => false]);

        [, $pending] = $this->reservationWithCode($stripe, null);

        $this->withServerVariables(['QUERY_STRING' => 'promo=OLD']);

        Livewire::withQueryParams(['promo' => 'OLD'])
            ->test(Checkout::class, ['domain' => $pending->slug])
            ->assertSet('promotionCode', 'OLD')
            ->assertSet('appliedPromotion', null)
            ->assertSet('promotionError', __('numerosis::billing.promotion.expired'));

        $this->assertNull($pending->refresh()->promotion_code);
    }

    public function test_removing_a_code_clears_it_from_the_reservation(): void
    {
        $stripe = $this->fakeStripe();
        $stripe->addPromotionCode('LAUNCH25');

        [, $pending] = $this->reservationWithCode($stripe, 'LAUNCH25');

        Livewire::test(Checkout::class, ['domain' => $pending->slug])
            ->assertSet('appliedPromotion', fn (?PromotionData $applied): bool => $applied?->code === 'LAUNCH25')
            ->call('removePromotionCode')
            ->assertSet('appliedPromotion', null);

        $this->assertNull($pending->refresh()->promotion_code);
    }

    /**
     * @return array{0: BaseCentralUser, 1: BaseTenantProvision}
     */
    private function reservationWithCode(FakeStripeHttpClient $stripe, ?string $code, int $trialDays = 0): array
    {
        Tenant::unsetEventDispatcher();

        $user = $this->signedInCustomer();
        $plan = $this->createStarterPlan('price_monthly');
        $plan->update(['trial_days' => $trialDays]);
        $setupIntent = $this->openSetupIntentFor($user);

        $pending = $this->reserve('acme', $user, $setupIntent->id, [
            'payment_plan' => $plan->slug,
            'billing_cycle' => BillingCycle::Monthly->value,
            'promotion_code' => $code,
        ]);

        $stripe->requests = [];

        return [$user, $pending];
    }

    /**
     * @return list<string>
     */
    private function promotionCodesSentWith(FakeStripeHttpClient $stripe, string $subscriptionId): array
    {
        $discounts = $stripe->subscriptionRecord($subscriptionId)['discounts'] ?? [];

        $codes = [];

        foreach (is_array($discounts) ? $discounts : [] as $discount) {
            $promotionCode = is_array($discount) ? ($discount['promotion_code'] ?? null) : null;
            $id = is_array($promotionCode) ? ($promotionCode['id'] ?? null) : null;

            if (is_string($id)) {
                $codes[] = $id;
            }
        }

        return $codes;
    }
}
