<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Tests\Feature\Actions\Billing;

use App\Models\Central\CentralUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Cashier\Cashier;
use Nvade\Numerosis\Actions\Billing\SyncBillingAddress;
use Nvade\Numerosis\Exceptions\Billing\InvalidVatNumber;
use Nvade\Numerosis\Testing\FakesStripe;
use Nvade\Numerosis\Tests\TestCase;
use Stripe\PaymentMethod;

/**
 * Creates a PaymentMethod with a billing address attached (standing in for
 * what the Address Element produces client-side), then asserts the address
 * lands on the Stripe customer and an EU VAT number becomes a tax id of the
 * right type. See .claude/plans/module-marketplace.md. Runs against
 * FakesStripe's in-memory fake, not live Stripe test mode — see D9 in
 * .claude/plans/package-extraction.md.
 */
class SyncBillingAddressTest extends TestCase
{
    use FakesStripe;
    use RefreshDatabase;

    public function test_it_writes_the_payment_methods_address_onto_the_customer(): void
    {
        $this->fakeStripe();
        $user = CentralUser::factory()->create();
        $customer = $user->createOrGetStripeCustomer();
        $paymentMethod = $this->paymentMethodWithAddress($customer->id, 'NL');

        SyncBillingAddress::run($user, $paymentMethod);

        $updated = Cashier::stripe()->customers->retrieve($user->stripeIdOrFail());

        $this->assertNotNull($updated->address);
        $this->assertSame('123 Main St', $updated->address->line1);
        $this->assertSame('NL', $updated->address->country);
    }

    public function test_an_eu_vat_number_becomes_a_tax_id_of_the_right_type(): void
    {
        $this->fakeStripe();
        $user = CentralUser::factory()->create();
        $customer = $user->createOrGetStripeCustomer();
        $paymentMethod = $this->paymentMethodWithAddress($customer->id, 'NL');

        SyncBillingAddress::run($user, $paymentMethod, 'NL860001655B01');

        $taxIds = Cashier::stripe()->customers->allTaxIds($user->stripeIdOrFail());

        $this->assertCount(1, $taxIds->data);
        $this->assertSame('eu_vat', $taxIds->data[0]->type);
    }

    public function test_a_vat_number_for_an_unsupported_country_is_refused(): void
    {
        $this->fakeStripe();
        $user = CentralUser::factory()->create();
        $customer = $user->createOrGetStripeCustomer();
        $paymentMethod = $this->paymentMethodWithAddress($customer->id, 'US');

        $this->expectException(InvalidVatNumber::class);

        SyncBillingAddress::run($user, $paymentMethod, 'NL860001655B01');
    }

    public function test_an_invalid_vat_number_surfaces_as_a_domain_exception(): void
    {
        $this->fakeStripe();
        $user = CentralUser::factory()->create();
        $customer = $user->createOrGetStripeCustomer();
        $paymentMethod = $this->paymentMethodWithAddress($customer->id, 'NL');

        $this->expectException(InvalidVatNumber::class);

        SyncBillingAddress::run($user, $paymentMethod, 'not-a-real-vat-number');
    }

    private function paymentMethodWithAddress(string $customerId, string $country): PaymentMethod
    {
        $paymentMethod = Cashier::stripe()->paymentMethods->create([
            'type' => 'card',
            'card' => ['token' => 'tok_visa'],
            'billing_details' => [
                'address' => [
                    'line1' => '123 Main St',
                    'city' => 'Amsterdam',
                    'postal_code' => '1000AA',
                    'country' => $country,
                ],
            ],
        ]);

        Cashier::stripe()->paymentMethods->attach($paymentMethod->id, ['customer' => $customerId]);

        return $paymentMethod;
    }
}
