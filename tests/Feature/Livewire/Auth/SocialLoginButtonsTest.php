<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Tests\Feature\Livewire\Auth;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Nvade\Numerosis\Tests\TestCase;

class SocialLoginButtonsTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The `login` screen this rendered through belonged to
     * nvade/numerosis-auth-ui's Livewire PasswordlessLogin, deleted (not
     * moved) when that package folded into core in Phase 3 of
     * `.claude/plans/humming-nibbling-flame.md`. Phase 4 rebuilds it on
     * Fortify — reinstate this assertion, against whatever screen renders
     * `<x-numerosis::auth.buttons.grid>` then.
     */
    public function test_it_only_renders_buttons_for_providers_configured_in_services(): void
    {
        $this->markTestSkipped('The login screen awaits the Fortify rebuild in Phase 4.');
    }
}
