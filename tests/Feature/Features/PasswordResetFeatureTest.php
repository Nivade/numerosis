<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Tests\Feature\Features;

use Illuminate\Support\Facades\Route;
use Nvade\Numerosis\Tests\TestCase;

class PasswordResetFeatureTest extends TestCase
{
    public function test_it_registers_the_routes_when_enabled(): void
    {
        $this->assertTrue(Route::has('password.request'));
        $this->assertTrue(Route::has('password.reset'));
        $this->assertTrue(Route::has('settings.password'));
    }
}
