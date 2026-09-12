<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Contracts\Billing;

use Illuminate\Support\Collection;
use Laravel\Cashier\PaymentMethod as CashierPaymentMethod;
use Laravel\Cashier\SubscriptionBuilder;
use Nvade\Numerosis\Contracts\Auth\CentralUserModel;
use Nvade\Numerosis\Contracts\Subscribable;
use Stripe\PaymentMethod;

/**
 * The central user shape every checkout path accepts: identity from
 * {@see CentralUserModel}, subscriptions from {@see Subscribable}, and the
 * slice of Cashier's `Billable` that checkout actually calls.
 *
 * Checkout narrows on this and not on the concrete `Models\Central\CentralUser`,
 * so a host model that implements the interfaces without extending a package
 * class — the case `HostConfig`'s `is_a()` check already assumes — reaches the
 * end of a checkout instead of failing it as a foreign session.
 *
 * Several methods below carry no native return type. That is not an omission:
 * Cashier declares none either, and an interface stricter than the trait
 * satisfying it is a fatal error. `global_id` and `stripe_id` are declared as
 * the accessors Cashier and stancl already provide, rather than as
 * `@property-read`, which PHPStan does not resolve through an interface.
 */
interface BillableUser extends CentralUserModel, Subscribable
{
    public function getGlobalIdentifierKey(): string;

    public function hasStripeId(): bool;

    public function stripeId(): ?string;

    public function stripeIdOrFail(): string;

    /**
     * @param  array<string, mixed>  $options
     * @param  array<string, mixed>  $requestOptions
     * @return \Stripe\Customer
     */
    public function createOrGetStripeCustomer(array $options = [], array $requestOptions = []);

    /**
     * @param  list<string>  $expand
     * @return \Stripe\Customer
     */
    public function asStripeCustomer(array $expand = []);

    /**
     * @param  array<string, mixed>  $options
     * @return \Stripe\Customer
     */
    public function updateStripeCustomer(array $options = []);

    /**
     * @param  array<string, mixed>  $options
     * @return \Stripe\SetupIntent
     */
    public function createSetupIntent(array $options = []);

    /**
     * @param  array<string, mixed>  $params
     * @param  array<string, mixed>  $options
     * @return \Stripe\SetupIntent
     */
    public function findSetupIntent(string $id, array $params = [], array $options = []);

    public function findPaymentMethod(PaymentMethod|string $paymentMethod): ?CashierPaymentMethod;

    /**
     * @param  array<string, mixed>  $parameters
     * @return Collection<int, CashierPaymentMethod>
     */
    public function paymentMethods(?string $type = null, array $parameters = []): Collection;

    /**
     * @param  string|list<string>  $prices
     */
    public function newSubscription(string $type, string|array $prices = []): SubscriptionBuilder;

    /**
     * @return array-key
     */
    public function getKey();
}
