<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Tests\Feature\Actions\Billing;

use App\Models\Central\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Cashier\Cashier;
use Nvade\Numerosis\Actions\Billing\AddVatNumber;
use Nvade\Numerosis\Exceptions\Billing\InvalidVatNumber;
use Nvade\Numerosis\Tests\TestCase;

class AddVatNumberTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_adds_a_vat_number_using_the_customers_existing_address(): void
    {
        Tenant::unsetEventDispatcher();
        $tenant = Tenant::factory()->create();

        $customer = $tenant->createOrGetStripeCustomer();

        Cashier::stripe()->customers->update($customer->id, [
            'address' => [
                'line1' => '123 Main St',
                'city' => 'Amsterdam',
                'postal_code' => '1000AA',
                'country' => 'NL',
            ],
        ]);

        AddVatNumber::run($tenant, 'NL860001655B01');

        $taxIds = Cashier::stripe()->customers->allTaxIds($tenant->stripeIdOrFail());

        $this->assertCount(1, $taxIds->data);
        $this->assertSame('eu_vat', $taxIds->data[0]->type);
    }

    public function test_it_refuses_a_country_it_cannot_infer_a_tax_id_type_for(): void
    {
        Tenant::unsetEventDispatcher();
        $tenant = Tenant::factory()->create();

        $customer = $tenant->createOrGetStripeCustomer();

        Cashier::stripe()->customers->update($customer->id, [
            'address' => [
                'line1' => '1 Infinite Loop',
                'city' => 'Cupertino',
                'postal_code' => '95014',
                'country' => 'US',
            ],
        ]);

        $this->expectException(InvalidVatNumber::class);

        AddVatNumber::run($tenant, 'NL860001655B01');
    }
}
