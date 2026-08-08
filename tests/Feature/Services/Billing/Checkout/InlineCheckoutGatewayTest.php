<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Tests\Feature\Services\Billing\Checkout;

use App\Models\Central\CentralUser;
use App\Models\Central\PaymentPlan;
use App\Models\Central\PendingTenantProvision;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Nvade\Numerosis\Data\Billing\Intents\InlineCheckout;
use Nvade\Numerosis\Data\Tenancy\TenantRegistrationData;
use Nvade\Numerosis\Enums\BillingCycle;
use Nvade\Numerosis\Services\Billing\Checkout\InlineCheckoutGateway;
use Nvade\Numerosis\Testing\FakesStripe;
use Nvade\Numerosis\Tests\TestCase;

/**
 * Runs against FakesStripe's in-memory fake, not live Stripe test mode —
 * see D9 in .claude/plans/package-extraction.md.
 */
class InlineCheckoutGatewayTest extends TestCase
{
    use FakesStripe;
    use RefreshDatabase;

    public function test_it_creates_a_setup_intent_and_returns_an_inline_checkout(): void
    {
        $this->fakeStripe();
        $user = CentralUser::factory()->create();
        $this->actingAs($user);

        // InlineCheckoutGateway::begin() only checks a price is configured
        // locally — it never sends it to Stripe (that happens later, in
        // CreateInlineSubscription) — so a placeholder id is fine here.
        PaymentPlan::create([
            'name' => 'Basic',
            'slug' => 'basic',
            'description' => 'Basic Plan',
            'monthly_id' => 'price_test_monthly',
            'yearly_id' => 'price_test_yearly',
            'monthly_price' => 1000,
            'yearly_price' => 10000,
            'available' => true,
        ]);

        PendingTenantProvision::factory()->create([
            'domain' => 'inline-test',
            'global_id' => $user->global_id,
        ]);

        $intent = resolve(InlineCheckoutGateway::class)->begin(new TenantRegistrationData(
            company_name: 'Inline Test Co',
            domain: 'inline-test',
            global_id: $user->global_id,
            payment_plan: 'basic',
            billing_cycle: BillingCycle::Monthly,
        ));

        $this->assertInstanceOf(InlineCheckout::class, $intent);
        $this->assertNotEmpty($intent->clientSecret);
        $this->assertNotEmpty($intent->publishableKey);

        $pending = PendingTenantProvision::find('inline-test');
        $this->assertNotNull($pending);
        $this->assertSame('basic', $pending->payment_plan);
        $this->assertSame(BillingCycle::Monthly, $pending->billing_cycle);
        $this->assertNotNull($pending->stripe_setup_intent_id);

        $user->refresh();
        $this->assertNotNull($user->stripe_id);
    }
}
