<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Tests\Feature\Features;

use Illuminate\Support\Facades\Route;
use Nvade\Numerosis\Tests\TestCase;

class MarketingPagesFeatureTest extends TestCase
{
    public function test_it_registers_the_marketing_routes_when_enabled(): void
    {
        $this->assertTrue(Route::has('terms'));
        $this->assertTrue(Route::has('privacy'));
        $this->assertTrue(Route::has('about'));
        $this->assertTrue(Route::has('features'));
    }

    public function test_home_stays_registered_regardless(): void
    {
        $this->assertTrue(Route::has('home'));
    }
}
