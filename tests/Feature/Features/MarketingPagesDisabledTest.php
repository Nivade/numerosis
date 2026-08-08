<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Tests\Feature\Features;

use Illuminate\Support\Facades\Route;
use Nvade\Numerosis\Support\Features;
use Nvade\Numerosis\Tests\TestCase;

class MarketingPagesDisabledTest extends TestCase
{
    protected function setUp(): void
    {
        Features::forceForTesting([]);

        parent::setUp();
    }

    public function test_it_registers_no_marketing_routes_when_disabled(): void
    {
        $this->assertFalse(Route::has('terms'));
        $this->assertFalse(Route::has('privacy'));
        $this->assertFalse(Route::has('about'));
        $this->assertFalse(Route::has('features'));
    }

    /**
     * 'home' is deliberately not part of this feature — see the class
     * docblock. Socialite's OAuth tenant-redirect and CompleteRedirectCheckout's
     * error fallback both depend on it always existing.
     */
    public function test_home_still_registers_when_disabled(): void
    {
        $this->assertTrue(Route::has('home'));
    }
}
