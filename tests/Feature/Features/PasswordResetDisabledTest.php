<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Tests\Feature\Features;

use Illuminate\Support\Facades\Route;
use Nvade\Numerosis\Support\Features;
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
     * the sensitive-action confirmation flow, a different concern from
     * resetting a forgotten password. See the feature class docblock.
     *
     * Route currently unregistered regardless of this feature: it belonged
     * to nvade/numerosis-auth-ui's Livewire ConfirmPassword, deleted (not
     * moved) when that package folded into core in Phase 3 of
     * `.claude/plans/humming-nibbling-flame.md`. Phase 4 rebuilds it on
     * Fortify — reinstate this assertion then.
     */
    public function test_password_confirm_still_registers_when_disabled(): void
    {
        $this->markTestSkipped('password.confirm awaits the Fortify rebuild in Phase 4.');
    }
}
