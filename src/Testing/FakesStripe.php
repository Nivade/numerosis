<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Testing;

use Stripe\ApiRequestor;
use Stripe\HttpClient\CurlClient;

/**
 * Makes Stripe deterministic and offline in tests.
 *
 * `Http::fake()` cannot do this: Stripe's SDK does its own HTTP and never
 * passes through Laravel's HTTP client, so this replaces the SDK's client
 * instead, and restores it afterwards.
 */
trait FakesStripe
{
    protected function fakeStripe(): FakeStripeHttpClient
    {
        $client = new FakeStripeHttpClient;

        ApiRequestor::setHttpClient($client);

        $this->beforeApplicationDestroyed(function (): void {
            ApiRequestor::setHttpClient(CurlClient::instance());
        });

        return $client;
    }
}
