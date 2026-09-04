<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Tests\Feature\Features;

use Illuminate\Support\Facades\Route;
use Nvade\Numerosis\Tests\TestCase;

class PasswordResetFeatureTest extends TestCase
{
    public function test_it_registers_the_routes_when_enabled(): void
    {
        $this->assertTrue(Route::has('settings.password'));
    }

    /**
     * These four are Fortify's, gated by `FortifyFeatures::resetPasswords()`,
     * which `NumerosisServiceProvider::registerFortify()` derives from this
     * feature — so the numerosis key is what turns them on and off.
     */
    public function test_it_registers_the_forgot_and_reset_password_routes_when_enabled(): void
    {
        $this->assertTrue(Route::has('password.request'));
        $this->assertTrue(Route::has('password.reset'));
        $this->assertTrue(Route::has('password.email'));
        $this->assertTrue(Route::has('password.update'));
    }
}
