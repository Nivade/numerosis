<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Tests\Concerns;

use Nvade\Numerosis\Tests\Support\FakeStripeHttpClient;
use Stripe\ApiRequestor;
use Stripe\HttpClient\CurlClient;

/**
 * Per D9 (.claude/plans/package-extraction.md): deterministic, offline
 * Stripe for tests, instead of a real key hitting the live API. Stripe's
 * SDK does its own HTTP under `\Stripe\ApiRequestor`, not through
 * `Illuminate\Http\Client` — `Http::fake()` never sees these calls, which
 * is why this swaps the SDK's client directly instead.
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
