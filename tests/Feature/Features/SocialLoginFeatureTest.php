<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Tests\Feature\Features;

use Illuminate\Support\Facades\Route;
use Nvade\Numerosis\Tests\TestCase;

class SocialLoginFeatureTest extends TestCase
{
    public function test_it_registers_oauth_routes_when_enabled(): void
    {
        $this->assertTrue(Route::has('oauth'));
        $this->assertTrue(Route::has('oauth.callback'));
    }
}
