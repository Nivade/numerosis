<?php

declare(strict_types=1);

use App\Models\Central\CentralUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Cashier\Cashier;
use Nvade\Numerosis\Actions\Billing\Checkout\ResolveSavedPaymentMethod;
use Nvade\Numerosis\Exceptions\Billing\SavedPaymentMethodUnavailable;

uses(RefreshDatabase::class);

test('it resolves a card payment method attached to the billable\'s own customer', function () {
    $user = CentralUser::factory()->create();
    $customer = $user->createOrGetStripeCustomer();
    $stripe = Cashier::stripe();

    $pm = $stripe->paymentMethods->create([
        'type' => 'card',
        'card' => ['token' => 'tok_visa'],
    ]);

    $stripe->paymentMethods->attach($pm->id, ['customer' => $customer->id]);

    $resolved = ResolveSavedPaymentMethod::run($user, $pm->id);

    expect($resolved->id)->toBe($pm->id);
});

test('it throws when the payment method belongs to a different stripe customer', function () {
    $user1 = CentralUser::factory()->create();
    $customer1 = $user1->createOrGetStripeCustomer();
    $user2 = CentralUser::factory()->create();
    $customer2 = $user2->createOrGetStripeCustomer();
    $stripe = Cashier::stripe();

    $pm = $stripe->paymentMethods->create([
        'type' => 'card',
        'card' => ['token' => 'tok_visa'],
    ]);

    $stripe->paymentMethods->attach($pm->id, ['customer' => $customer1->id]);

    expect(fn () => ResolveSavedPaymentMethod::run($user2, $pm->id))
        ->toThrow(SavedPaymentMethodUnavailable::class);
});
