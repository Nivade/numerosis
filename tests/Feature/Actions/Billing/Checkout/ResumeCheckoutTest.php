<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Tests\Feature\Actions\Billing\Checkout;

use App\Models\Central\CentralUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Nvade\Numerosis\Actions\Billing\Checkout\ResumeCheckout;
use Nvade\Numerosis\Exceptions\Billing\CheckoutSessionExpired;
use Nvade\Numerosis\Testing\FakesStripe;
use Nvade\Numerosis\Tests\Concerns\CreatesCheckoutFixtures;
use Nvade\Numerosis\Tests\TestCase;

class ResumeCheckoutTest extends TestCase
{
    use CreatesCheckoutFixtures;
    use FakesStripe;
    use RefreshDatabase;

    public function test_it_resumes_a_checkout_owned_by_the_caller(): void
    {
        $this->fakeStripe();
        $user = $this->signedInCustomer();

        $setupIntent = $this->openSetupIntentFor($user);

        $this->reserve('resume-test', $user, $setupIntent->id);

        $resumed = ResumeCheckout::run('resume-test');

        $this->assertSame($setupIntent->client_secret, $resumed->clientSecret);
        $this->assertFalse($resumed->alreadySucceeded);
    }

    /**
     * Same ownership rule as ResolveSetupIntent — a checkout link must not
     * resume anyone else's reservation.
     */
    public function test_it_refuses_another_users_domain(): void
    {
        $victim = CentralUser::factory()->create();
        $attacker = CentralUser::factory()->create();
        $this->actingAs($attacker);

        $this->reserve('not-yours', $victim, 'seti_not_the_attackers');

        $this->expectException(CheckoutSessionExpired::class);

        ResumeCheckout::run('not-yours');
    }

    public function test_it_refuses_a_domain_with_no_pending_reservation(): void
    {
        $this->actingAs(CentralUser::factory()->create());

        $this->expectException(CheckoutSessionExpired::class);

        ResumeCheckout::run('does-not-exist');
    }

    public function test_it_refuses_a_reservation_with_no_setup_intent_yet(): void
    {
        $user = $this->signedInCustomer();

        $this->reserve('no-intent-yet', $user, null);

        $this->expectException(CheckoutSessionExpired::class);

        ResumeCheckout::run('no-intent-yet');
    }
}
