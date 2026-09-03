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
     * `password.request`/`password.reset` belonged to
     * nvade/numerosis-auth-ui's Livewire ForgotPassword/ResetPassword,
     * deleted (not moved) when that package folded into core in Phase 3 of
     * `.claude/plans/humming-nibbling-flame.md`. Phase 4 rebuilds both on
     * Fortify — reinstate this assertion then.
     */
    public function test_it_registers_the_forgot_and_reset_password_routes_when_enabled(): void
    {
        $this->markTestSkipped('password.request/password.reset await the Fortify rebuild in Phase 4.');
    }
}
