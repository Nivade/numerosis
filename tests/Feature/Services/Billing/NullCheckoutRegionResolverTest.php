<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Tests\Feature\Services\Billing;

use Illuminate\Http\Request;
use Nvade\Numerosis\Contracts\Billing\CheckoutRegionResolver;
use Nvade\Numerosis\Services\Billing\NullCheckoutRegionResolver;
use Nvade\Numerosis\Tests\TestCase;

/**
 * Core ships no IP-to-country lookup, so what is worth holding is the
 * *contract*: null means "use `numerosis.billing.payment_methods.default_order`",
 * never an error. A host binds its own resolver and every caller has to keep
 * behaving when it does not.
 */
class NullCheckoutRegionResolverTest extends TestCase
{
    public function test_it_returns_null_for_a_request_with_an_ip(): void
    {
        $request = Request::create('/checkout/acme', 'GET', server: ['REMOTE_ADDR' => '203.0.113.5']);

        $this->assertNull(new NullCheckoutRegionResolver()->resolve($request));
    }

    public function test_it_returns_null_for_a_request_without_an_ip(): void
    {
        $request = new Request;

        $this->assertNull($request->ip());
        $this->assertNull(new NullCheckoutRegionResolver()->resolve($request));
    }

    public function test_the_contract_resolves_to_the_null_implementation_by_default(): void
    {
        $this->assertInstanceOf(NullCheckoutRegionResolver::class, resolve(CheckoutRegionResolver::class));
    }
}
