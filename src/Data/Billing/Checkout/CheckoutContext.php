<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Data\Billing\Checkout;

use Nvade\Numerosis\Enums\FetchState;

/**
 * Everything the checkout screen needs to render, in one value. The component
 * holds it instead of holding the order the six lookups behind it run in, and
 * which of them may fail. Not a Spatie Data object, since it never crosses the
 * wire.
 */
final readonly class CheckoutContext
{
    /**
     * @param  list<string>  $paymentMethodOrder
     * @param  array{name: ?string, address: array{line1: ?string, line2: ?string, city: ?string, state: ?string, postal_code: ?string, country: ?string}}|null  $savedBillingAddress
     * @param  array<int, array<string, mixed>>  $savedPaymentMethods
     */
    public function __construct(
        public ?string $detectedCountry = null,
        public array $paymentMethodOrder = [],
        public ?string $customerEmail = null,
        public ?string $vatNumber = null,
        public ?array $savedBillingAddress = null,
        public FetchState $savedBillingFetchState = FetchState::NotAttempted,
        public array $savedPaymentMethods = [],
        public FetchState $savedPaymentMethodsFetchState = FetchState::NotAttempted,
        public ?ResumedCheckout $resumed = null,
        public ?string $error = null,
    ) {}
}
