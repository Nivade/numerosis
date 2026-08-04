<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Tests\Feature\Features;

use Nvade\Numerosis\Support\Features;
use Illuminate\Support\Facades\Route;
use Nvade\Numerosis\Tests\TestCase;

class PasswordResetDisabledTest extends TestCase
{
    protected function setUp(): void
    {
        Features::forceForTesting([]);

        parent::setUp();
    }

    public function test_it_registers_no_routes_when_disabled(): void
    {
        $this->assertFalse(Route::has('password.request'));
        $this->assertFalse(Route::has('password.reset'));
        $this->assertFalse(Route::has('settings.password'));
    }

    /**
     * password.confirm is deliberately not part of this feature — it backs
     * Filament's own sensitive-action confirmation flow, a different
     * concern from resetting a forgotten password. See the feature class
     * docblock.
     */
    public function test_password_confirm_still_registers_when_disabled(): void
    {
        $this->assertTrue(Route::has('password.confirm'));
    }
}
