<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Tests\Feature\Actions\Billing\Checkout;

use App\Models\Central\CentralUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Cashier\Cashier;
use Nvade\Numerosis\Actions\Billing\Checkout\ResolveSavedPaymentMethod;
use Nvade\Numerosis\Exceptions\Billing\SavedPaymentMethodUnavailable;
use Nvade\Numerosis\Testing\FakesStripe;
use Nvade\Numerosis\Tests\TestCase;

class ResolveSavedPaymentMethodTest extends TestCase
{
    use FakesStripe;
    use RefreshDatabase;

    public function test_it_resolves_a_card_payment_method_attached_to_the_billables_own_customer(): void
    {
        $this->fakeStripe();
        $user = CentralUser::factory()->create();
        $customer = $user->createOrGetStripeCustomer();
        $stripe = Cashier::stripe();

        $pm = $stripe->paymentMethods->create([
            'type' => 'card',
            'card' => ['token' => 'tok_visa'],
        ]);

        $stripe->paymentMethods->attach($pm->id, ['customer' => $customer->id]);

        $resolved = ResolveSavedPaymentMethod::run($user, $pm->id);

        $this->assertSame($pm->id, $resolved->id);
    }

    public function test_it_throws_when_the_payment_method_belongs_to_a_different_stripe_customer(): void
    {
        $this->fakeStripe();
        $user1 = CentralUser::factory()->create();
        $customer1 = $user1->createOrGetStripeCustomer();
        $user2 = CentralUser::factory()->create();
        $user2->createOrGetStripeCustomer();
        $stripe = Cashier::stripe();

        $pm = $stripe->paymentMethods->create([
            'type' => 'card',
            'card' => ['token' => 'tok_visa'],
        ]);

        $stripe->paymentMethods->attach($pm->id, ['customer' => $customer1->id]);

        $this->expectException(SavedPaymentMethodUnavailable::class);

        ResolveSavedPaymentMethod::run($user2, $pm->id);
    }
}
