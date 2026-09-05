<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Tests\Feature\Features;

use Illuminate\Support\Facades\Route;
use Nvade\Numerosis\Support\Features;
use Nvade\Numerosis\Tests\TestCase;

class SocialLoginDisabledTest extends TestCase
{
    protected function setUp(): void
    {
        Features::forceForTesting([]);

        parent::setUp();
    }

    public function test_it_registers_no_social_route_when_disabled(): void
    {
        $this->assertFalse(Route::has('social.redirect'));
        $this->assertFalse(Route::has('social.callback'));
        $this->assertFalse(Route::has('social.destroy'));
        $this->assertFalse(Route::has('settings.connected-accounts'));
    }
}
