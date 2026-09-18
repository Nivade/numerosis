<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Contracts\Billing;

use Illuminate\Support\Collection;
use Laravel\Cashier\PaymentMethod as CashierPaymentMethod;
use Laravel\Cashier\PromotionCode;
use Laravel\Cashier\SubscriptionBuilder;
use Nvade\Numerosis\Contracts\Auth\CentralUserModel;
use Nvade\Numerosis\Contracts\Subscribable;
use Stripe\Customer;
use Stripe\PaymentMethod;
use Stripe\SetupIntent;

/**
 * The central user shape every checkout path accepts, so a host model that
 * implements the interfaces without extending a package class still reaches
 * the end of a checkout.
 *
 * Several methods below carry no native return type: Cashier declares none,
 * and an interface stricter than the trait satisfying it is a fatal error.
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
     * @return Customer
     */
    public function createOrGetStripeCustomer(array $options = [], array $requestOptions = []);

    /**
     * @param  list<string>  $expand
     * @return Customer
     */
    public function asStripeCustomer(array $expand = []);

    /**
     * @param  array<string, mixed>  $options
     * @return Customer
     */
    public function updateStripeCustomer(array $options = []);

    /**
     * @param  array<string, mixed>  $options
     * @return SetupIntent
     */
    public function createSetupIntent(array $options = []);

    /**
     * @param  array<string, mixed>  $params
     * @param  array<string, mixed>  $options
     * @return SetupIntent
     */
    public function findSetupIntent(string $id, array $params = [], array $options = []);

    public function findPaymentMethod(PaymentMethod|string $paymentMethod): ?CashierPaymentMethod;

    /**
     * Unfiltered on purpose: an inactive code has to come back so the customer
     * hears "expired" instead of "unknown".
     *
     * @param  array<string, mixed>  $options
     */
    public function findPromotionCode(string $code, array $options = []): ?PromotionCode;

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
