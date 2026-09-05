<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Tests\Feature;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Route;
use Nvade\Numerosis\Support\Features;
use Nvade\Numerosis\Tests\TestCase;

/**
 * `home` is the one central route core guarantees, and the only page core
 * still renders itself.
 *
 * The marketing pages that used to live beside it (`terms`, `privacy`,
 * `about`, `features`, and a real homepage) moved to the host app: they are
 * the product's, not the framework's. What core keeps is the route name —
 * Socialite's OAuth tenant redirect, `CompleteRedirectCheckout`'s error
 * fallback and the tenant panel all fall back to it — plus a placeholder view
 * behind `numerosis.routes.home_view`.
 */
class HomeRouteTest extends TestCase
{
    protected function setUp(): void
    {
        Features::forceForTesting([]);

        parent::setUp();
    }

    public function test_home_registers_with_every_feature_disabled(): void
    {
        $this->assertTrue(Route::has('home'));
    }

    /**
     * Core must not ship the product's marketing site. A host registers these
     * itself, through `Numerosis::addCentralRoutes()`.
     */
    public function test_core_registers_no_marketing_routes(): void
    {
        $this->assertFalse(Route::has('terms'));
        $this->assertFalse(Route::has('privacy'));
        $this->assertFalse(Route::has('about'));
        $this->assertFalse(Route::has('features'));
    }

    public function test_the_home_view_is_configurable(): void
    {
        $this->assertSame('numerosis::home', Config::string('numerosis.routes.home_view'));
    }
}
