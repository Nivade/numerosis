<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Tests\Feature\Actions\Billing\Checkout;

use App\Models\Central\CentralUser;
use App\Models\Central\PendingTenantProvision;
use App\Models\Central\Subscription;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Nvade\Numerosis\Actions\Billing\Checkout\SettleCheckout;
use Nvade\Numerosis\Enums\Billing\BillingCycle;
use Nvade\Numerosis\Enums\Tenancy\TenantProvisionStatus;
use Nvade\Numerosis\Facades\Billing;
use Nvade\Numerosis\Tests\TestCase;

class SettleCheckoutTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_provisions_immediately_when_the_subscription_is_active(): void
    {
        $fake = Billing::fake();

        $user = CentralUser::factory()->create();
        $pending = PendingTenantProvision::factory()->create([
            'domain' => 'settle-active',
            'global_id' => $user->global_id,
            'payment_plan' => 'starter',
            'billing_cycle' => BillingCycle::Monthly,
        ]);
        $subscription = Subscription::factory()->make([
            'stripe_id' => 'sub_active',
            'stripe_status' => 'active',
        ]);

        SettleCheckout::run($pending, $subscription, 'cus_123', (string) $user->id);

        $pending->refresh();
        $this->assertSame(TenantProvisionStatus::Provisioning, $pending->status);
        $this->assertSame('sub_active', $pending->stripe_subscription_id);

        $fake->assertTenantProvisioned('settle-active');
    }

    /**
     * Unreachable for cards in production (they never leave the subscription
     * in a non-active/trialing state after create()), but the gate must
     * still provision — see SettleCheckout's docblock.
     */
    public function test_it_still_provisions_when_the_subscription_is_not_yet_settled(): void
    {
        $fake = Billing::fake();

        $user = CentralUser::factory()->create();
        $pending = PendingTenantProvision::factory()->create([
            'domain' => 'settle-processing',
            'global_id' => $user->global_id,
            'payment_plan' => 'starter',
            'billing_cycle' => BillingCycle::Monthly,
        ]);
        $subscription = Subscription::factory()->make([
            'stripe_id' => 'sub_processing',
            'stripe_status' => 'incomplete',
        ]);

        SettleCheckout::run($pending, $subscription, 'cus_123', (string) $user->id);

        $pending->refresh();
        $this->assertSame(TenantProvisionStatus::AwaitingPayment, $pending->status);

        $fake->assertTenantProvisioned('settle-processing');
    }
}
