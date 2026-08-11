<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Tests\Feature\Actions\Billing\Checkout;

use Illuminate\Http\Request;
use Nvade\Numerosis\Actions\Billing\Checkout\ResolveCheckoutRegion;
use Nvade\Numerosis\Tests\TestCase;
use RuntimeException;
use Torann\GeoIP\Facades\GeoIP;
use Torann\GeoIP\Location;

class ResolveCheckoutRegionTest extends TestCase
{
    public function test_it_returns_the_iso_code_for_a_confident_lookup(): void
    {
        GeoIP::shouldReceive('getLocation')
            ->once()
            ->with('203.0.113.5')
            ->andReturn(new Location(['iso_code' => 'NL', 'default' => false]));

        $request = Request::create('/checkout/acme', 'GET', server: ['REMOTE_ADDR' => '203.0.113.5']);

        $this->assertSame('NL', ResolveCheckoutRegion::run($request));
    }

    public function test_it_returns_null_when_geoip_falls_back_to_its_default_location(): void
    {
        GeoIP::shouldReceive('getLocation')
            ->once()
            ->andReturn(new Location(['iso_code' => 'US', 'default' => true]));

        $request = Request::create('/checkout/acme', 'GET', server: ['REMOTE_ADDR' => '127.0.0.1']);

        $this->assertNull(ResolveCheckoutRegion::run($request));
    }

    public function test_it_returns_null_and_reports_when_the_driver_throws(): void
    {
        GeoIP::shouldReceive('getLocation')
            ->once()
            ->andThrow(new RuntimeException('MaxMind database file missing'));

        $request = Request::create('/checkout/acme', 'GET', server: ['REMOTE_ADDR' => '203.0.113.5']);

        $this->assertNull(ResolveCheckoutRegion::run($request));
    }

    public function test_it_returns_null_without_calling_geoip_when_the_request_has_no_ip(): void
    {
        GeoIP::shouldReceive('getLocation')->never();

        $request = new Request;

        $this->assertNull($request->ip());
        $this->assertNull(ResolveCheckoutRegion::run($request));
    }
}
