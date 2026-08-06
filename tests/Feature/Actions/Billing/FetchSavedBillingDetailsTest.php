<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Tests\Feature\Actions\Billing;

use App\Models\Central\CentralUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Cashier\Cashier;
use Nvade\Numerosis\Actions\Billing\FetchSavedBillingDetails;
use Nvade\Numerosis\Tests\Concerns\FakesStripe;
use Nvade\Numerosis\Tests\TestCase;

class FetchSavedBillingDetailsTest extends TestCase
{
    use FakesStripe;
    use RefreshDatabase;

    public function test_it_returns_empty_details_for_a_billable_with_no_stripe_id(): void
    {
        $this->fakeStripe();
        $user = CentralUser::factory()->create();

        $result = FetchSavedBillingDetails::run($user);

        $this->assertFalse($result->fetchFailed);
        $this->assertFalse($result->hasAddress());
        $this->assertNull($result->vatNumber);
    }

    public function test_it_returns_empty_details_for_a_customer_with_no_address_set(): void
    {
        $this->fakeStripe();
        $user = CentralUser::factory()->create();
        $user->createOrGetStripeCustomer();

        $result = FetchSavedBillingDetails::run($user);

        $this->assertFalse($result->fetchFailed);
        $this->assertFalse($result->hasAddress());
        $this->assertNull($result->vatNumber);
    }

    public function test_it_returns_populated_address_and_vat_for_a_customer_with_both_set(): void
    {
        $this->fakeStripe();
        $user = CentralUser::factory()->create();
        $customer = $user->createOrGetStripeCustomer();

        $stripe = Cashier::stripe();

        $stripe->customers->update($customer->id, [
            'name' => 'John Doe',
            'address' => [
                'line1' => '123 Main St',
                'line2' => 'Apt 4',
                'city' => 'San Francisco',
                'state' => 'CA',
                'postal_code' => '94103',
                'country' => 'US',
            ],
        ]);

        $stripe->customers->createTaxId($customer->id, [
            'type' => 'eu_vat',
            'value' => 'DE123456789',
        ]);

        $result = FetchSavedBillingDetails::run($user);

        $this->assertFalse($result->fetchFailed);
        $this->assertTrue($result->hasAddress());
        $this->assertSame('John Doe', $result->name);
        $this->assertSame('123 Main St', $result->line1);
        $this->assertSame('Apt 4', $result->line2);
        $this->assertSame('San Francisco', $result->city);
        $this->assertSame('CA', $result->state);
        $this->assertSame('94103', $result->postalCode);
        $this->assertSame('US', $result->country);
        $this->assertSame('DE123456789', $result->vatNumber);
    }
}
