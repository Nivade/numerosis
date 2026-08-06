<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Tests\Support;

use RuntimeException;
use Stripe\HttpClient\ClientInterface;

/**
 * A minimal, stateful in-memory Stripe API, not a static fixture player.
 * `AddVatNumberTest`'s own flow (create a customer, update its address,
 * retrieve it back, attach a tax id, list tax ids) proved that recorded
 * static responses are not enough here — the test reads back what an
 * earlier call in the *same* test wrote, so the fake has to actually hold
 * that state across requests the way real Stripe does. See D9 in
 * .claude/plans/package-extraction.md: "recorded fixtures" undersold the
 * shape this needed to take.
 *
 * Deliberately narrow: only the resources/operations a numerosis test
 * currently exercises are implemented. An unhandled (method, path) throws
 * immediately with both, so a test hitting a new endpoint fails loudly
 * with "add a handler here" rather than silently returning nothing —
 * the same reason `Http::preventStrayRequests()` exists for the facade
 * this class stands in for (Stripe's SDK does not go through
 * `Illuminate\Http\Client`, so that facade never sees these calls at all).
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

    private int $sequence = 0;

    /**
     * @param  list<string>  $headers
     * @param  array<string, mixed>  $params
     * @return array{0: string, 1: int, 2: array<string, mixed>}
     */
    public function request($method, $absUrl, $headers, $params, $hasFile, $apiMode = 'v1', $maxNetworkRetries = null): array
    {
        $path = (string) parse_url($absUrl, PHP_URL_PATH);
        $segments = array_values(array_filter(explode('/', $path)));
        // ['v1', 'customers', 'cus_1', 'tax_ids'] etc — drop the leading 'v1'.
        array_shift($segments);

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
                $segments === ['setup_intents'] && $method === 'post' => $this->createSetupIntent($params),
                \count($segments) === 2 && $segments[0] === 'setup_intents' && $method === 'get' => $this->retrieveSetupIntent($segments[1], $params),
                \count($segments) === 3 && $segments[0] === 'setup_intents' && $segments[2] === 'confirm' && $method === 'post' => $this->confirmSetupIntent($segments[1], $params),
                default => throw new RuntimeException("FakeStripeHttpClient has no handler for {$method} {$path} — add one, this is not a real Stripe API call."),
            };
        } catch (FakeStripeApiError $e) {
            return [json_encode(['error' => $e->errorBody], JSON_THROW_ON_ERROR), $e->status, []];
        }

        return [json_encode($body, JSON_THROW_ON_ERROR), 200, []];
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
     * A confirmed SetupIntent (`confirm: true` plus a test token like
     * `pm_card_visa`) implies a PaymentMethod that was never created via a
     * separate call in the test — real Stripe test tokens work the same
     * way. Creates it under the literal id given, rather than a generated
     * one, if it doesn't already exist.
     *
     * @return array<string, mixed>
     */
    private function ensurePaymentMethod(string $id, string $type = 'card'): array
    {
        if (! isset($this->paymentMethods[$id])) {
            $this->paymentMethods[$id] = [
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
        }

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
        if (! isset($this->setupIntents[$id])) {
            throw new FakeStripeApiError(404, [
                'message' => "No such setup_intent: '{$id}'",
                'type' => 'invalid_request_error',
                'code' => 'resource_missing',
            ]);
        }

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
        if (! isset($this->setupIntents[$id])) {
            throw new FakeStripeApiError(404, [
                'message' => "No such setup_intent: '{$id}'",
                'type' => 'invalid_request_error',
                'code' => 'resource_missing',
            ]);
        }

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

        if ($customerId === '') {
            throw new RuntimeException('FakeStripeHttpClient: attach requires a customer param.');
        }

        $this->retrieveCustomer($customerId);

        $this->paymentMethods[$id]['customer'] = $customerId;

        return $this->paymentMethods[$id];
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

        if (! preg_match('/^[A-Z]{2}[A-Z0-9]+$/i', $value)) {
            throw new FakeStripeApiError(400, [
                'message' => "Invalid tax ID {$value}",
                'type' => 'invalid_request_error',
                'param' => 'value',
                'code' => 'tax_id_invalid',
            ]);
        }

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
 * Stripe-shaped error response (`{"error": {...}}`, non-2xx status) instead of
 * a success body — the shape `Stripe\ApiRequestor::handleErrorResponse()`
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
