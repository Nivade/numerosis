<?php

declare(strict_types=1);

use App\Models\Central\CentralUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Cashier\Cashier;
use Nvade\Numerosis\Actions\Billing\FetchReusablePaymentMethods;

uses(RefreshDatabase::class);

test('it returns empty for a billable with no stripe_id', function () {
    $user = CentralUser::factory()->create();

    $result = FetchReusablePaymentMethods::run($user);

    expect($result->fetchFailed)->toBeFalse();
    expect($result->options)->toBeEmpty();
});

test('it returns empty for a customer with no attached payment methods', function () {
    $user = CentralUser::factory()->create();
    $user->createOrGetStripeCustomer();

    $result = FetchReusablePaymentMethods::run($user);

    expect($result->fetchFailed)->toBeFalse();
    expect($result->options)->toBeEmpty();
});

test('it returns a card payment method marked as default when matching', function () {
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

    expect($result->fetchFailed)->toBeFalse();
    expect($result->options)->toHaveCount(1);

    if (! $option instanceof Nvade\Numerosis\Data\Billing\SavedPaymentMethodOption) {
        throw new RuntimeException('Expected a saved payment method option.');
    }

    expect($option->id)->toBe($pm->id);
    expect($option->brand)->toBe('visa');
    expect($option->last4)->toBe('4242');
    expect($option->expMonth)->toBeGreaterThan(0);
    expect($option->expYear)->toBeGreaterThan(0);
    expect($option->isDefault)->toBeTrue();
});
