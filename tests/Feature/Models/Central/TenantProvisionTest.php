<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Tests\Feature\Models\Central;

use App\Models\Central\TenantProvision;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Nvade\Numerosis\Enums\Billing\BillingCycle;
use Nvade\Numerosis\Tests\TestCase;

class TenantProvisionTest extends TestCase
{
    use RefreshDatabase;

    public function test_claim_setup_intent_writes_the_plan_and_setup_intent(): void
    {
        $provision = TenantProvision::factory()->create([
            'slug' => 'claim-test',
            'global_id' => 'owner-1',
        ]);

        TenantProvision::claimSetupIntent(
            slug: 'claim-test',
            globalId: 'owner-1',
            paymentPlan: 'basic',
            billingCycle: BillingCycle::Monthly,
            setupIntentId: 'seti_owned',
        );

        $provision->refresh();
        $this->assertSame('basic', $provision->payment_plan);
        $this->assertSame(BillingCycle::Monthly, $provision->billing_cycle);
        $this->assertSame('seti_owned', $provision->stripe_setup_intent_id);
    }

    /**
     * The ownership predicate is the whole point of this method: an
     * unscoped write would let anyone who guesses a slug overwrite the
     * reservation's SetupIntent and lock the real owner out of it.
     */
    public function test_claim_setup_intent_does_not_overwrite_a_stranger_s_reservation(): void
    {
        $provision = TenantProvision::factory()->create([
            'slug' => 'claim-test',
            'global_id' => 'owner-1',
            'stripe_setup_intent_id' => 'seti_original',
        ]);

        TenantProvision::claimSetupIntent(
            slug: 'claim-test',
            globalId: 'not-the-owner',
            paymentPlan: 'basic',
            billingCycle: BillingCycle::Monthly,
            setupIntentId: 'seti_attacker',
        );

        $provision->refresh();
        $this->assertSame('seti_original', $provision->stripe_setup_intent_id);
        $this->assertNull($provision->payment_plan);
    }
}
