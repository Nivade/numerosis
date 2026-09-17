<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Testing;

use RuntimeException;
use Stripe\HttpClient\ClientInterface;

/**
 * A small, stateful, in-memory Stripe API for tests. Stateful because these
 * flows read back what an earlier call in the same test wrote. Only the
 * operations the package's own tests exercise are implemented; an unhandled
 * request throws, naming the method and path.
 */
class FakeStripeHttpClient implements ClientInterface
{
    /** @var array<string, array<string, mixed>> */
    private array $customers = [];

    /** @var array<string, list<array<string, mixed>>> keyed by customer id */
    private array $taxIds = [];

    /** @var array<string, array<string, mixed>> */
    private array $paymentMethods = [];

    /** @var array<string, array<string, mixed>> */
    private array $setupIntents = [];

    /** @var array<string, array<string, mixed>> */
    private array $subscriptions = [];

    /**
     * How far out a materialized subscription item's period runs, which is
     * what Cashier's `cancel()` writes to `subscriptions.ends_at`.
     */
    public int $currentPeriodEndsInDays = 30;

    /**
     * Accepted meter events, keyed by the identifier they carried.
     *
     * @var array<string, array{event_name: string, value: int, stripe_customer_id: string}>
     */
    public array $meterEvents = [];

    /**
     * Pinned aggregated totals per meter id, for tests that need Stripe to
     * disagree with the local counter.
     *
     * @var array<string, int>
     */
    public array $meterSummaries = [];

    /**
     * Promotion codes a test seeded, keyed by the customer-facing code.
     *
     * @var array<string, array<string, mixed>>
     */
    public array $promotionCodes = [];

    /**
     * Coupons the seeded promotion codes point at, keyed by coupon id.
     *
     * @var array<string, array<string, mixed>>
     */
    public array $coupons = [];

    /**
     * Products per price id, for coupons restricted to products.
     *
     * @var array<string, string>
     */
    public array $priceProducts = [];

    private int $sequence = 0;

    /**
     * Every call the SDK made, in order, so a test can assert how many times a
     * path was hit rather than only what came back.
     *
     * @var list<array{method: string, path: string}>
     */
    public array $requests = [];

    /**
     * @param  array<array-key, mixed>  $headers
     * @param  array<array-key, mixed>  $params
     * @return array{0: string, 1: int, 2: array<string, mixed>}
     */
    public function request($method, $absUrl, $headers, $params, $hasFile, $apiMode = 'v1', $maxNetworkRetries = null): array
    {
        /** @var array<string, mixed> $params */
        /** @var string $absUrl */
        $path = (string) parse_url($absUrl, PHP_URL_PATH);
        $segments = array_values(array_filter(explode('/', $path), static fn (string $segment): bool => $segment !== ''));
        // ['v1', 'customers', 'cus_1', 'tax_ids'] etc. Drop the leading 'v1'.
        array_shift($segments);

        $this->requests[] = ['method' => (string) $method, 'path' => $path];

        try {
            $body = match (true) {
                $segments === ['customers'] && $method === 'post' => $this->createCustomer($params),
                \count($segments) === 2 && $segments[0] === 'customers' && $method === 'get' => $this->retrieveCustomer($segments[1], $params),
                \count($segments) === 2 && $segments[0] === 'customers' && $method === 'post' => $this->updateCustomer($segments[1], $params),
                \count($segments) === 3 && $segments[0] === 'customers' && $segments[2] === 'tax_ids' && $method === 'post' => $this->createTaxId($segments[1], $params),
                \count($segments) === 3 && $segments[0] === 'customers' && $segments[2] === 'tax_ids' && $method === 'get' => $this->listTaxIds($segments[1]),
                $segments === ['payment_methods'] && $method === 'post' => $this->createPaymentMethod($params),
                \count($segments) === 2 && $segments[0] === 'payment_methods' && $method === 'get' => $this->retrievePaymentMethod($segments[1]),
                \count($segments) === 3 && $segments[0] === 'payment_methods' && $segments[2] === 'attach' && $method === 'post' => $this->attachPaymentMethod($segments[1], $params),
                \count($segments) === 3 && $segments[0] === 'customers' && $segments[2] === 'payment_methods' && $method === 'get' => $this->listPaymentMethods($segments[1], $params),
                $segments === ['payment_methods'] && $method === 'get' => $this->listAllPaymentMethods($params),
                $segments === ['setup_intents'] && $method === 'post' => $this->createSetupIntent($params),
                \count($segments) === 2 && $segments[0] === 'setup_intents' && $method === 'get' => $this->retrieveSetupIntent($segments[1], $params),
                \count($segments) === 3 && $segments[0] === 'setup_intents' && $segments[2] === 'confirm' && $method === 'post' => $this->confirmSetupIntent($segments[1], $params),
                $segments === ['subscriptions'] && $method === 'post' => $this->createSubscription($params),
                \count($segments) === 2 && $segments[0] === 'subscriptions' && $method === 'get' => $this->retrieveSubscription($segments[1]),
                \count($segments) === 2 && $segments[0] === 'subscriptions' && $method === 'post' => $this->updateSubscription($segments[1], $params),
                \count($segments) === 2 && $segments[0] === 'subscription_items' && $method === 'get' => $this->retrieveSubscriptionItem($segments[1]),
                $segments === ['promotion_codes'] && $method === 'get' => $this->listPromotionCodes($params),
                \count($segments) === 2 && $segments[0] === 'coupons' && $method === 'get' => $this->retrieveCoupon($segments[1]),
                \count($segments) === 2 && $segments[0] === 'prices' && $method === 'get' => $this->retrievePrice($segments[1]),
                $segments === ['billing', 'meter_events'] && $method === 'post' => $this->createMeterEvent($params),
                \count($segments) === 4 && $segments[0] === 'billing' && $segments[1] === 'meters' && $segments[3] === 'event_summaries' && $method === 'get' => $this->listEventSummaries($segments[2]),
                default => throw new RuntimeException("FakeStripeHttpClient has no handler for {$method} {$path} — add one, this is not a real Stripe API call."),
            };
        } catch (FakeStripeApiError $e) {
            return [json_encode(['error' => $e->errorBody], JSON_THROW_ON_ERROR), $e->status, []];
        }

        return [json_encode($body, JSON_THROW_ON_ERROR), 200, []];
    }

    /**
     * Stripe deduplicates meter events on their identifier, so a second event
     * carrying one already seen is accepted and ignored. Tests assert against
     * this array to prove a retry did not bill twice.
     *
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    private function createMeterEvent(array $params): array
    {
        $identifier = $this->stringParam($params, 'identifier');
        $payload = $this->arrayParam($params, 'payload');

        if (! array_key_exists($identifier, $this->meterEvents)) {
            $value = $payload['value'] ?? 0;
            $customerId = $payload['stripe_customer_id'] ?? '';

            $this->meterEvents[$identifier] = [
                'event_name' => $this->stringParam($params, 'event_name'),
                'value' => is_numeric($value) ? (int) $value : 0,
                'stripe_customer_id' => is_scalar($customerId) ? (string) $customerId : '',
            ];
        }

        return [
            'object' => 'v2.billing.meter_event',
            'identifier' => $identifier,
            'event_name' => $this->stringParam($params, 'event_name'),
            'created' => now()->toIso8601ZuluString(),
        ];
    }

    /**
     * Summed off the accepted events unless a test pins a total, which is how
     * a divergence between Stripe and the local counter is seeded.
     *
     * @return array<string, mixed>
     */
    private function listEventSummaries(string $meterId): array
    {
        $aggregated = $this->meterSummaries[$meterId]
            ?? array_sum(array_map(static fn (array $event): int => $event['value'], $this->meterEvents));

        return [
            'object' => 'list',
            'data' => [[
                'object' => 'billing.meter_event_summary',
                'id' => $this->id('mtrsum'),
                'meter' => $meterId,
                'aggregated_value' => $aggregated,
            ]],
        ];
    }

    /**
     * The subscription as the fake holds it, for a test asserting on what was
     * sent rather than on what came back.
     *
     * @return array<string, mixed>|null
     */
    public function subscriptionRecord(string $id): ?array
    {
        return $this->subscriptions[$id] ?? null;
    }

    /**
     * Seeds a usable promotion code and its coupon, overridable per field so a
     * test can make exactly one thing wrong with it.
     *
     * @param  array<string, mixed>  $promotionCode
     * @param  array<string, mixed>  $coupon
     */
    public function addPromotionCode(string $code, array $promotionCode = [], array $coupon = []): void
    {
        $couponId = $this->id('coupon');

        $this->coupons[$couponId] = $this->couponPayload(['id' => $couponId, ...$coupon]);

        $this->promotionCodes[$code] = [
            'id' => $this->id('promo'),
            'object' => 'promotion_code',
            'code' => $code,
            'active' => true,
            'customer' => null,
            'expires_at' => null,
            'max_redemptions' => null,
            'times_redeemed' => 0,
            'promotion' => ['type' => 'coupon', 'coupon' => $this->coupons[$couponId]],
            'restrictions' => [
                'first_time_transaction' => false,
                'minimum_amount' => null,
                'minimum_amount_currency' => null,
            ],
            ...$promotionCode,
        ];
    }

    /**
     * Pins a discount onto a subscription, the shape `Subscription::discount()`
     * reads back.
     *
     * @param  array<string, mixed>  $discount
     */
    public function addSubscriptionDiscount(string $subscriptionId, array $discount): void
    {
        $this->retrieveSubscription($subscriptionId);

        // An empty array clears it, which is how a test says the discount ended.
        $this->subscriptions[$subscriptionId]['discounts'] = $discount === [] ? [] : [$discount];
    }

    /**
     * A coupon with every field Stripe sends, so reading an absent one does not
     * print a notice and turn a passing test risky.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    public function couponPayload(array $overrides = []): array
    {
        return [
            'id' => $this->id('coupon'),
            'object' => 'coupon',
            'valid' => true,
            'percent_off' => 25,
            'amount_off' => null,
            'currency' => null,
            'duration' => 'once',
            'duration_in_months' => null,
            'redeem_by' => null,
            'applies_to' => null,
            ...$overrides,
        ];
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    private function listPromotionCodes(array $params): array
    {
        $code = $this->stringParam($params, 'code');
        $found = $this->promotionCodes[$code] ?? null;

        // `active=true` is Stripe's own filter, and the package deliberately
        // does not pass it: an inactive code has to come back as inactive.
        if ($found !== null && ($params['active'] ?? null) === true && ($found['active'] ?? true) !== true) {
            $found = null;
        }

        return [
            'object' => 'list',
            'url' => '/v1/promotion_codes',
            'has_more' => false,
            'data' => $found === null ? [] : [$found],
        ];
    }

    /** @return array<string, mixed> */
    private function retrieveCoupon(string $id): array
    {
        return $this->coupons[$id] ?? [
            'id' => $id,
            'object' => 'coupon',
            'valid' => true,
            'duration' => 'once',
        ];
    }

    /** @return array<string, mixed> */
    private function retrievePrice(string $id): array
    {
        return [
            'id' => $id,
            'object' => 'price',
            'product' => $this->priceProducts[$id] ?? 'prod_fake',
        ];
    }

    private function id(string $prefix): string
    {
        return $prefix.'_fake'.(++$this->sequence);
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    private function arrayParam(array $params, string $key): array
    {
        $value = $params[$key] ?? [];

        return is_array($value) ? $value : [];
    }

    /** @param  array<string, mixed>  $params */
    private function stringParam(array $params, string $key): string
    {
        $value = $params[$key] ?? '';

        return is_scalar($value) ? (string) $value : '';
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    private function createPaymentMethod(array $params): array
    {
        $id = $this->id('pm');

        $address = array_merge([
            'line1' => null,
            'line2' => null,
            'city' => null,
            'state' => null,
            'postal_code' => null,
            'country' => null,
        ], $this->arrayParam($this->arrayParam($params, 'billing_details'), 'address'));

        $paymentMethod = [
            'id' => $id,
            'object' => 'payment_method',
            'type' => $params['type'] ?? 'card',
            'customer' => null,
            'billing_details' => array_merge([
                'address' => $address,
                'name' => null,
                'email' => null,
                'phone' => null,
            ], $this->arrayParam($params, 'billing_details'), ['address' => $address]),
            'card' => $params['type'] === 'card' ? ['brand' => 'visa', 'last4' => '4242', 'exp_month' => 12, 'exp_year' => 2030] : null,
        ];

        $this->paymentMethods[$id] = $paymentMethod;

        return $paymentMethod;
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    private function listPaymentMethods(string $customerId, array $params): array
    {
        $this->retrieveCustomer($customerId);

        $type = $this->stringParam($params, 'type');

        $data = array_values(array_filter(
            $this->paymentMethods,
            fn (array $pm): bool => ($pm['customer'] ?? null) === $customerId
                && ($type === '' || $pm['type'] === $type),
        ));

        return [
            'object' => 'list',
            'data' => $data,
            'has_more' => false,
            'url' => "/v1/customers/{$customerId}/payment_methods",
        ];
    }

    /**
     * `Billable::paymentMethods()` hits this top-level, customer-filtered
     * list endpoint rather than `GET /v1/customers/{id}/payment_methods`
     * ({@see listPaymentMethods()}) — a genuinely different Stripe endpoint,
     * not a second spelling of the same call.
     *
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    private function listAllPaymentMethods(array $params): array
    {
        $customerId = $this->stringParam($params, 'customer');
        $type = $this->stringParam($params, 'type');

        if ($customerId !== '') {
            $this->retrieveCustomer($customerId);
        }

        $data = array_values(array_filter(
            $this->paymentMethods,
            fn (array $pm): bool => ($customerId === '' || ($pm['customer'] ?? null) === $customerId)
                && ($type === '' || $pm['type'] === $type),
        ));

        return [
            'object' => 'list',
            'data' => $data,
            'has_more' => false,
            'url' => '/v1/payment_methods',
        ];
    }

    /**
     * A confirmed SetupIntent (`confirm: true` plus a test token like
     * `pm_card_visa`) implies a PaymentMethod that was never created via a
     * separate call in the test, exactly as real Stripe test tokens do.
     * Creates it under the literal id given when it does not already exist.
     *
     * @return array<string, mixed>
     */
    private function ensurePaymentMethod(string $id, string $type = 'card'): array
    {
        $this->paymentMethods[$id] ??= [
            'id' => $id,
            'object' => 'payment_method',
            'type' => $type,
            'customer' => null,
            'billing_details' => [
                'address' => ['line1' => null, 'line2' => null, 'city' => null, 'state' => null, 'postal_code' => null, 'country' => null],
                'name' => null,
                'email' => null,
                'phone' => null,
            ],
            'card' => $type === 'card' ? ['brand' => 'visa', 'last4' => '4242', 'exp_month' => 12, 'exp_year' => 2030] : null,
        ];

        return $this->paymentMethods[$id];
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    private function createSetupIntent(array $params): array
    {
        $id = $this->id('seti');
        $customerId = $this->stringParam($params, 'customer');
        $paymentMethodId = $this->stringParam($params, 'payment_method');
        $confirm = (bool) ($params['confirm'] ?? false);

        if ($paymentMethodId !== '') {
            $this->ensurePaymentMethod($paymentMethodId);
        }

        $confirmed = $confirm && $paymentMethodId !== '';

        if ($confirmed) {
            $this->paymentMethods[$paymentMethodId]['customer'] = $customerId;
        }

        $setupIntent = [
            'id' => $id,
            'object' => 'setup_intent',
            'client_secret' => "{$id}_secret_fake",
            'customer' => $customerId !== '' ? $customerId : null,
            'payment_method' => $paymentMethodId !== '' ? $paymentMethodId : null,
            'payment_method_types' => $this->arrayParam($params, 'payment_method_types') ?: ['card'],
            'status' => $confirmed ? 'succeeded' : 'requires_payment_method',
        ];

        $this->setupIntents[$id] = $setupIntent;

        return $setupIntent;
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    private function confirmSetupIntent(string $id, array $params): array
    {
        throw_unless(isset($this->setupIntents[$id]), FakeStripeApiError::class, 404, [
            'message' => "No such setup_intent: '{$id}'",
            'type' => 'invalid_request_error',
            'code' => 'resource_missing',
        ]);

        $paymentMethodId = $this->stringParam($params, 'payment_method');

        if ($paymentMethodId !== '') {
            $this->ensurePaymentMethod($paymentMethodId);

            $customerId = $this->setupIntents[$id]['customer'];

            if (is_string($customerId)) {
                $this->paymentMethods[$paymentMethodId]['customer'] = $customerId;
            }

            $this->setupIntents[$id]['payment_method'] = $paymentMethodId;
        }

        $this->setupIntents[$id]['status'] = 'succeeded';

        return $this->setupIntents[$id];
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    private function retrieveSetupIntent(string $id, array $params = []): array
    {
        throw_unless(isset($this->setupIntents[$id]), FakeStripeApiError::class, 404, [
            'message' => "No such setup_intent: '{$id}'",
            'type' => 'invalid_request_error',
            'code' => 'resource_missing',
        ]);

        $setupIntent = $this->setupIntents[$id];
        $expand = $params['expand'] ?? [];

        if (is_array($expand) && in_array('payment_method', $expand, true) && is_string($setupIntent['payment_method'] ?? null)) {
            $setupIntent['payment_method'] = $this->retrievePaymentMethod($setupIntent['payment_method']);
        }

        return $setupIntent;
    }

    /** @return array<string, mixed> */
    private function retrievePaymentMethod(string $id): array
    {
        return $this->paymentMethods[$id] ?? throw new FakeStripeApiError(404, [
            'message' => "No such payment_method: '{$id}'",
            'type' => 'invalid_request_error',
            'code' => 'resource_missing',
        ]);
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    private function attachPaymentMethod(string $id, array $params): array
    {
        $this->retrievePaymentMethod($id);

        $customerId = $this->stringParam($params, 'customer');

        throw_if($customerId === '', RuntimeException::class, 'FakeStripeHttpClient: attach requires a customer param.');

        $this->retrieveCustomer($customerId);

        $this->paymentMethods[$id]['customer'] = $customerId;

        return $this->paymentMethods[$id];
    }

    /**
     * Materialized on first sight, like {@see ensurePaymentMethod()}: a local
     * `subscriptions` row created by a factory has a Stripe id nothing here
     * ever posted.
     *
     * @return array<string, mixed>
     */
    /**
     * Enough of a created subscription for Cashier to persist one: an active
     * status, so `handlePaymentFailure()` finds nothing to confirm. The
     * `discounts` payload is kept as sent, which is how a test proves a
     * promotion code reached subscription creation rather than being applied
     * afterwards.
     *
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    private function createSubscription(array $params): array
    {
        $id = $this->id('sub');
        $items = $this->arrayParam($params, 'items');

        $lines = [];

        foreach ($items as $item) {
            $price = is_array($item) ? ($item['price'] ?? null) : null;
            $priceId = is_string($price) ? $price : 'price_fake';

            $lines[] = [
                'id' => $this->id('si'),
                'object' => 'subscription_item',
                'quantity' => is_array($item) ? ($item['quantity'] ?? 1) : 1,
                'price' => [
                    'id' => $priceId,
                    'object' => 'price',
                    'product' => $this->priceProducts[$priceId] ?? 'prod_fake',
                ],
                'current_period_start' => today()->getTimestamp(),
                'current_period_end' => now()->addDays($this->currentPeriodEndsInDays)->getTimestamp(),
            ];
        }

        $this->subscriptions[$id] = [
            'id' => $id,
            'object' => 'subscription',
            'status' => isset($params['trial_end']) ? 'trialing' : 'active',
            'cancel_at_period_end' => false,
            'customer' => $params['customer'] ?? null,
            'billing_mode' => ['type' => 'flexible'],
            'discounts' => $this->discountsFrom($this->arrayParam($params, 'discounts')),
            'metadata' => $this->arrayParam($params, 'metadata'),
            'items' => ['object' => 'list', 'data' => $lines, 'has_more' => false, 'url' => '/v1/subscription_items'],
        ];

        return $this->subscriptions[$id];
    }

    /**
     * Stripe answers with discount objects, not with the `{promotion_code: id}`
     * pairs it was given.
     *
     * @param  array<array-key, mixed>  $discounts
     * @return list<array<string, mixed>>
     */
    private function discountsFrom(array $discounts): array
    {
        $resolved = [];

        foreach ($discounts as $discount) {
            if (! is_array($discount)) {
                continue;
            }

            $promotionCodeId = $discount['promotion_code'] ?? null;
            $promotionCode = null;

            foreach ($this->promotionCodes as $candidate) {
                if (($candidate['id'] ?? null) === $promotionCodeId) {
                    $promotionCode = $candidate;
                }
            }

            $couponId = $discount['coupon'] ?? null;
            $promotion = $promotionCode['promotion'] ?? null;

            $coupon = is_string($couponId)
                ? $this->retrieveCoupon($couponId)
                : (is_array($promotion) ? ($promotion['coupon'] ?? null) : null);

            // `source.coupon` is where the current API keeps it, and Cashier
            // reads whichever shape `StripeApiVersions::current()` names.
            $resolved[] = [
                'id' => $this->id('di'),
                'object' => 'discount',
                'coupon' => $coupon,
                'source' => ['type' => 'coupon', 'coupon' => $coupon],
                'promotion_code' => $promotionCode,
                'end' => null,
            ];
        }

        return $resolved;
    }

    /** @return array<string, mixed> */
    private function retrieveSubscription(string $id): array
    {
        $this->subscriptions[$id] ??= [
            'id' => $id,
            'object' => 'subscription',
            'status' => 'active',
            'cancel_at_period_end' => false,
            'customer' => null,
            'billing_mode' => ['type' => 'flexible'],
            'items' => ['object' => 'list', 'data' => [], 'has_more' => false, 'url' => '/v1/subscription_items'],
        ];

        return $this->subscriptions[$id];
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    private function updateSubscription(string $id, array $params): array
    {
        $this->retrieveSubscription($id);

        $this->subscriptions[$id] = array_merge($this->subscriptions[$id], $params);

        return $this->subscriptions[$id];
    }

    /** @return array<string, mixed> */
    private function retrieveSubscriptionItem(string $id): array
    {
        return [
            'id' => $id,
            'object' => 'subscription_item',
            'current_period_end' => now()->addDays($this->currentPeriodEndsInDays)->getTimestamp(),
        ];
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    private function createCustomer(array $params): array
    {
        $id = $this->id('cus');

        $customer = array_merge([
            'id' => $id,
            'object' => 'customer',
            'address' => null,
            'name' => null,
            'email' => null,
            'phone' => null,
            'metadata' => [],
            'invoice_settings' => ['default_payment_method' => null],
        ], $params);

        $this->customers[$id] = $customer;
        $this->taxIds[$id] = [];

        return $customer;
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    private function retrieveCustomer(string $id, array $params = []): array
    {
        $customer = $this->customers[$id] ?? throw new FakeStripeApiError(404, [
            'message' => "No such customer: '{$id}'",
            'type' => 'invalid_request_error',
            'code' => 'resource_missing',
        ]);

        $expand = $params['expand'] ?? [];

        if (is_array($expand) && in_array('tax_ids', $expand, true)) {
            $customer['tax_ids'] = $this->listTaxIds($id);
        }

        return $customer;
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    private function updateCustomer(string $id, array $params): array
    {
        $this->retrieveCustomer($id);

        if (isset($params['address'])) {
            $params['address'] = array_merge([
                'line1' => null,
                'line2' => null,
                'city' => null,
                'state' => null,
                'postal_code' => null,
                'country' => null,
            ], $this->arrayParam($params, 'address'));
        }

        $this->customers[$id] = array_merge($this->customers[$id], $params);

        return $this->customers[$id];
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    private function createTaxId(string $customerId, array $params): array
    {
        $this->retrieveCustomer($customerId);

        $value = $this->stringParam($params, 'value');

        throw_unless(preg_match('/^[A-Z]{2}[A-Z0-9]+$/i', $value), FakeStripeApiError::class, 400, [
            'message' => "Invalid tax ID {$value}",
            'type' => 'invalid_request_error',
            'param' => 'value',
            'code' => 'tax_id_invalid',
        ]);

        $taxId = [
            'id' => $this->id('txi'),
            'object' => 'tax_id',
            'customer' => $customerId,
            'type' => $params['type'] ?? null,
            'value' => $params['value'] ?? null,
        ];

        $this->taxIds[$customerId][] = $taxId;

        return $taxId;
    }

    /** @return array<string, mixed> */
    private function listTaxIds(string $customerId): array
    {
        $this->retrieveCustomer($customerId);

        return [
            'object' => 'list',
            'data' => $this->taxIds[$customerId],
            'has_more' => false,
            'url' => "/v1/customers/{$customerId}/tax_ids",
        ];
    }
}

/**
 * Thrown by a handler to make {@see FakeStripeHttpClient::request()} return a
 * Stripe-shaped error response (`{"error": {...}}`, non-2xx status) in place
 * of a success body: the shape `Stripe\ApiRequestor::handleErrorResponse()`
 * expects in order to raise the real `ApiErrorException` subclasses
 * application code catches.
 */
class FakeStripeApiError extends RuntimeException
{
    /** @param  array<string, mixed>  $errorBody */
    public function __construct(public readonly int $status, public readonly array $errorBody)
    {
        $message = $errorBody['message'] ?? 'Fake Stripe API error';

        parent::__construct(is_scalar($message) ? (string) $message : 'Fake Stripe API error');
    }
}
