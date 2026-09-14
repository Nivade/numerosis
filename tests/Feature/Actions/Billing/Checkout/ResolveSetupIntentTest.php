<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Tests\Feature\Actions\Billing\Checkout;

use App\Models\Central\CentralUser;
use App\Models\Central\Subscription;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Cashier\Cashier;
use Nvade\Numerosis\Actions\Billing\Checkout\ResolveSetupIntent;
use Nvade\Numerosis\Exceptions\Billing\CheckoutAlreadyCompleted;
use Nvade\Numerosis\Exceptions\Billing\CheckoutSessionExpired;
use Nvade\Numerosis\Exceptions\Billing\SetupIntentNotConfirmed;
use Nvade\Numerosis\Testing\FakesStripe;
use Nvade\Numerosis\Tests\Concerns\CreatesCheckoutFixtures;
use Nvade\Numerosis\Tests\TestCase;

class ResolveSetupIntentTest extends TestCase
{
    use CreatesCheckoutFixtures;
    use FakesStripe;
    use RefreshDatabase;

    public function test_it_resolves_a_confirmed_setup_intent_owned_by_the_caller(): void
    {
        $this->fakeStripe();
        $user = $this->signedInCustomer();

        $setupIntent = $this->confirmedSetupIntentFor($user);

        $pending = $this->reserve('resolve-test', $user, $setupIntent->id);

        $resolved = ResolveSetupIntent::run($setupIntent->id);

        $this->assertSame($pending->slug, $resolved->pending->slug);
        $this->assertSame($setupIntent->payment_method, $resolved->paymentMethodId());
        $this->assertSame($setupIntent->payment_method, $resolved->paymentMethod->id);
    }

    public function test_it_refuses_a_setup_intent_with_no_matching_pending_row(): void
    {
        $this->signedInCustomer();

        $this->expectException(CheckoutSessionExpired::class);

        ResolveSetupIntent::run('seti_does_not_exist');
    }

    public function test_it_refuses_a_setup_intent_reserved_by_another_account(): void
    {
        $user = CentralUser::factory()->create();
        $owner = CentralUser::factory()->create();
        $this->actingAs($user);

        $this->reserve('foreign-reservation', $owner, 'seti_belongs_to_someone_else');

        $this->expectException(CheckoutSessionExpired::class);

        ResolveSetupIntent::run('seti_belongs_to_someone_else');
    }

    public function test_it_refuses_an_unconfirmed_setup_intent(): void
    {
        $this->fakeStripe();
        $user = $this->signedInCustomer();

        $setupIntent = Cashier::stripe()->setupIntents->create([
            'customer' => $user->createOrGetStripeCustomer()->id,
        ]);

        $this->reserve('unconfirmed-test', $user, $setupIntent->id);

        $this->expectException(SetupIntentNotConfirmed::class);

        ResolveSetupIntent::run($setupIntent->id);
    }

    /**
     * Guards against a replayed subscribe() call (double-click, or a retry
     * after the browser never saw the first response): once the pending row
     * carries a subscription id and that subscription is still live, a
     * second resolve of the same SetupIntent must not be allowed to reach
     * CreateInlineSubscription again — that would create, and charge, a
     * second subscription for the same domain.
     */
    public function test_it_refuses_a_setup_intent_already_turned_into_a_live_subscription(): void
    {
        $this->fakeStripe();
        $user = $this->signedInCustomer();

        $setupIntent = $this->confirmedSetupIntentFor($user);

        $this->reserve('already-completed-test', $user, $setupIntent->id, [
            'stripe_subscription_id' => 'sub_already_settled',
        ]);

        Subscription::create([
            'subscribable_id' => $user->id,
            'subscribable_type' => CentralUser::class,
            'type' => 'default',
            'stripe_id' => 'sub_already_settled',
            'stripe_status' => 'active',
            'stripe_price' => 'price_test',
            'quantity' => 1,
        ]);

        $this->expectException(CheckoutAlreadyCompleted::class);

        ResolveSetupIntent::run($setupIntent->id);
    }

    /**
     * The guard is an allowlist against replay, not a permanent lock: a
     * subscription recorded as cancelled must not block resolving the
     * SetupIntent again.
     */
    public function test_it_allows_resolving_again_when_the_recorded_subscription_was_cancelled(): void
    {
        $this->fakeStripe();
        $user = $this->signedInCustomer();

        $setupIntent = $this->confirmedSetupIntentFor($user);

        $this->reserve('cancelled-completed-test', $user, $setupIntent->id, [
            'stripe_subscription_id' => 'sub_previously_cancelled',
        ]);

        Subscription::create([
            'subscribable_id' => $user->id,
            'subscribable_type' => CentralUser::class,
            'type' => 'default',
            'stripe_id' => 'sub_previously_cancelled',
            'stripe_status' => 'canceled',
            'stripe_price' => 'price_test',
            'quantity' => 1,
        ]);

        $resolved = ResolveSetupIntent::run($setupIntent->id);

        $this->assertSame('cancelled-completed-test', $resolved->pending->slug);
    }
}
