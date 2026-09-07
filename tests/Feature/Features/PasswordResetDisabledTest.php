<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Tests\Feature\Features;

use Illuminate\Support\Facades\Route;
use Nvade\Numerosis\Support\FeatureRegistry;
use Nvade\Numerosis\Tests\TestCase;

class PasswordResetDisabledTest extends TestCase
{
    protected function setUp(): void
    {
        FeatureRegistry::forceForTesting([]);

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
     * the sensitive-action confirmation flow, a different concern from
     * resetting a forgotten password. See the feature class docblock.
     *
     * Fortify registers it from its own `routes/routes.php`, outside
     * `fortify.features` entirely, which is why turning this feature off
     * leaves it standing.
     */
    public function test_password_confirm_still_registers_when_disabled(): void
    {
        $this->assertTrue(Route::has('password.confirm'));
    }
}
