<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Tests\Feature\Actions\Billing\Checkout;

use App\Models\Central\CentralUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Nvade\Numerosis\Actions\Billing\Checkout\LoadCheckoutContext;
use Nvade\Numerosis\Enums\FetchState;
use Nvade\Numerosis\Testing\FakesStripe;
use Nvade\Numerosis\Tests\Concerns\CreatesCheckoutFixtures;
use Nvade\Numerosis\Tests\TestCase;

class LoadCheckoutContextTest extends TestCase
{
    use CreatesCheckoutFixtures;
    use FakesStripe;
    use RefreshDatabase;

    public function test_it_carries_the_resumed_checkout_and_the_default_method_order(): void
    {
        $this->fakeStripe();
        $user = $this->signedInCustomer();

        $setupIntent = $this->openSetupIntentFor($user);
        $this->reserve('context-resume', $user, $setupIntent->id);

        $context = LoadCheckoutContext::run('context-resume', Request::create('/checkout/context-resume'));

        $this->assertSame($setupIntent->client_secret, $context->resumed?->clientSecret);
        $this->assertNull($context->error);
        $this->assertSame($user->email, $context->customerEmail);
        $this->assertSame(Config::array('numerosis.billing.payment_methods.default_order'), $context->paymentMethodOrder);
    }

    /**
     * The component renders the message; nothing about a foreign or missing
     * reservation may reach it as an exception.
     */
    public function test_a_reservation_that_is_not_the_callers_comes_back_as_an_error_not_a_throw(): void
    {
        $this->fakeStripe();
        $victim = CentralUser::factory()->create();
        $this->actingAs(CentralUser::factory()->create());

        $this->reserve('context-foreign', $victim, 'seti_not_the_callers');

        $context = LoadCheckoutContext::run('context-foreign', Request::create('/checkout/context-foreign'));

        $this->assertNull($context->resumed);
        $this->assertSame(__('numerosis::billing.checkout.foreign_session'), $context->error);
    }

    public function test_a_customer_stripe_cannot_be_retrieved_for_marks_both_fetches_failed(): void
    {
        $this->fakeStripe();
        $user = CentralUser::factory()->create(['stripe_id' => 'cus_invalid']);
        $this->actingAs($user);

        $this->reserve('context-fetch-fail', $user, 'seti_test');

        $context = LoadCheckoutContext::run('context-fetch-fail', Request::create('/checkout/context-fetch-fail'));

        $this->assertSame(FetchState::Failed, $context->savedBillingFetchState);
        $this->assertSame(FetchState::Failed, $context->savedPaymentMethodsFetchState);
        $this->assertNull($context->savedBillingAddress);
    }
}
