<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Tests\Feature\Actions\Billing\Checkout;

use Illuminate\Http\Request;
use Nvade\Numerosis\Actions\Billing\Checkout\ResolveCheckoutRegion;
use Nvade\Numerosis\Tests\TestCase;

/**
 * `torann/geoip` was dropped in Phase 6 of
 * `.claude/plans/humming-nibbling-flame.md`, so this action has no lookup
 * behind it and always answers null. What is worth holding is the
 * *contract*: null means "use `numerosis.billing.payment_methods.default_order`",
 * never an error — a host that wires a lookup back in replaces the action,
 * and every caller has to keep behaving when it does not.
 */
class ResolveCheckoutRegionTest extends TestCase
{
    public function test_it_returns_null_for_a_request_with_an_ip(): void
    {
        $request = Request::create('/checkout/acme', 'GET', server: ['REMOTE_ADDR' => '203.0.113.5']);

        $this->assertNull(ResolveCheckoutRegion::run($request));
    }

    public function test_it_returns_null_for_a_request_without_an_ip(): void
    {
        $request = new Request;

        $this->assertNull($request->ip());
        $this->assertNull(ResolveCheckoutRegion::run($request));
    }
}
