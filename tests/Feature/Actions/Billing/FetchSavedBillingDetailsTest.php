<?php

declare(strict_types=1);

use App\Models\Central\CentralUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Cashier\Cashier;
use Nvade\Numerosis\Actions\Billing\FetchSavedBillingDetails;

uses(RefreshDatabase::class);

test('it returns empty details for a billable with no stripe_id', function () {
    $user = CentralUser::factory()->create();

    $result = FetchSavedBillingDetails::run($user);

    expect($result->fetchFailed)->toBeFalse();
    expect($result->hasAddress())->toBeFalse();
    expect($result->vatNumber)->toBeNull();
});

test('it returns empty details for a customer with no address set', function () {
    $user = CentralUser::factory()->create();
    $user->createOrGetStripeCustomer();

    $result = FetchSavedBillingDetails::run($user);

    expect($result->fetchFailed)->toBeFalse();
    expect($result->hasAddress())->toBeFalse();
    expect($result->vatNumber)->toBeNull();
});

test('it returns populated address and vat for a customer with both set', function () {
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

    expect($result->fetchFailed)->toBeFalse();
    expect($result->hasAddress())->toBeTrue();
    expect($result->name)->toBe('John Doe');
    expect($result->line1)->toBe('123 Main St');
    expect($result->line2)->toBe('Apt 4');
    expect($result->city)->toBe('San Francisco');
    expect($result->state)->toBe('CA');
    expect($result->postalCode)->toBe('94103');
    expect($result->country)->toBe('US');
    expect($result->vatNumber)->toBe('DE123456789');
});
