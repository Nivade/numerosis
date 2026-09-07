<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Tests\Feature\Actions\Billing\Checkout;

use App\Models\Central\CentralUser;
use App\Models\Central\PendingTenantProvision;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Nvade\Numerosis\Actions\Billing\Checkout\SettleAttachedPaymentMethod;
use Nvade\Numerosis\Testing\FakesStripe;
use Nvade\Numerosis\Tests\Concerns\CreatesCheckoutFixtures;
use Nvade\Numerosis\Tests\TestCase;

/**
 * Extracted from `WebhookController::finalizeIfPaymentMethodMatches()`. Its
 * two refusals — a reservation with no SetupIntent, and a PaymentMethod
 * belonging to a different one — decide whether a checkout is settled at all,
 * and were only reachable through a signed Stripe webhook POST.
 */
class SettleAttachedPaymentMethodTest extends TestCase
{
    use CreatesCheckoutFixtures;
    use FakesStripe;
    use RefreshDatabase;

    public function test_a_reservation_with_no_setup_intent_is_not_a_match(): void
    {
        $user = CentralUser::factory()->create();

        $pending = PendingTenantProvision::factory()->create([
            'domain' => 'no-setup-intent',
            'global_id' => $user->global_id,
            'stripe_setup_intent_id' => null,
        ]);

        $this->assertFalse(SettleAttachedPaymentMethod::run($pending, $user, 'pm_whatever'));
        $this->assertNull($pending->refresh()->stripe_subscription_id);
    }

    public function test_a_payment_method_from_another_setup_intent_is_not_a_match(): void
    {
        $this->fakeStripe();

        $user = CentralUser::factory()->create();

        $pending = PendingTenantProvision::factory()->create([
            'domain' => 'other-payment-method',
            'global_id' => $user->global_id,
            'stripe_setup_intent_id' => $this->confirmedSetupIntentFor($user)->id,
        ]);

        $this->assertFalse(SettleAttachedPaymentMethod::run($pending, $user, 'pm_belongs_to_someone_else'));
        $this->assertNull($pending->refresh()->stripe_subscription_id);
    }
}
