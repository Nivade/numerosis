<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Tests\Feature\Actions\Billing;

use App\Models\Central\CentralUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Cashier\Cashier;
use Nvade\Numerosis\Actions\Billing\FetchReusablePaymentMethods;
use Nvade\Numerosis\Data\Billing\SavedPaymentMethodOption;
use Nvade\Numerosis\Testing\FakesStripe;
use Nvade\Numerosis\Tests\TestCase;
use RuntimeException;

class FetchReusablePaymentMethodsTest extends TestCase
{
    use FakesStripe;
    use RefreshDatabase;

    public function test_it_returns_empty_for_a_billable_with_no_stripe_id(): void
    {
        $this->fakeStripe();
        $user = CentralUser::factory()->create();

        $result = FetchReusablePaymentMethods::run($user);

        $this->assertFalse($result->fetchFailed);
        $this->assertTrue($result->options->isEmpty());
    }

    public function test_it_returns_empty_for_a_customer_with_no_attached_payment_methods(): void
    {
        $this->fakeStripe();
        $user = CentralUser::factory()->create();
        $user->createOrGetStripeCustomer();

        $result = FetchReusablePaymentMethods::run($user);

        $this->assertFalse($result->fetchFailed);
        $this->assertTrue($result->options->isEmpty());
    }

    public function test_it_returns_a_card_payment_method_marked_as_default_when_matching(): void
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
        $stripe->customers->update($customer->id, [
            'invoice_settings' => ['default_payment_method' => $pm->id],
        ]);

        $result = FetchReusablePaymentMethods::run($user);
        $option = $result->options->first();

        $this->assertFalse($result->fetchFailed);
        $this->assertCount(1, $result->options);

        if (! $option instanceof SavedPaymentMethodOption) {
            throw new RuntimeException('Expected a saved payment method option.');
        }

        $this->assertSame($pm->id, $option->id);
        $this->assertSame('visa', $option->brand);
        $this->assertSame('4242', $option->last4);
        $this->assertGreaterThan(0, $option->expMonth);
        $this->assertGreaterThan(0, $option->expYear);
        $this->assertTrue($option->isDefault);
    }
}
