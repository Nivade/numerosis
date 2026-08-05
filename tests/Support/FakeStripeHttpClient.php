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

        $body = match (true) {
            $segments === ['customers'] && $method === 'post' => $this->createCustomer($params),
            \count($segments) === 2 && $segments[0] === 'customers' && $method === 'get' => $this->retrieveCustomer($segments[1]),
            \count($segments) === 2 && $segments[0] === 'customers' && $method === 'post' => $this->updateCustomer($segments[1], $params),
            \count($segments) === 3 && $segments[0] === 'customers' && $segments[2] === 'tax_ids' && $method === 'post' => $this->createTaxId($segments[1], $params),
            \count($segments) === 3 && $segments[0] === 'customers' && $segments[2] === 'tax_ids' && $method === 'get' => $this->listTaxIds($segments[1]),
            default => throw new RuntimeException("FakeStripeHttpClient has no handler for {$method} {$path} — add one, this is not a real Stripe API call."),
        };

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
        ], $params);

        $this->customers[$id] = $customer;
        $this->taxIds[$id] = [];

        return $customer;
    }

    /** @return array<string, mixed> */
    private function retrieveCustomer(string $id): array
    {
        return $this->customers[$id] ?? throw new RuntimeException("FakeStripeHttpClient: no customer {$id} was created in this test.");
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    private function updateCustomer(string $id, array $params): array
    {
        $this->retrieveCustomer($id);

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
