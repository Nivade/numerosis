<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Tests\Feature\Livewire;

use App\Models\Central\CentralUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Livewire\Livewire;
use Nvade\Numerosis\Livewire\Settings\Password;
use Nvade\Numerosis\Tests\TestCase;

/**
 * Regression for the convention-registration audit
 * (.claude/plans/archive/package-extraction.md, step 2): these classes had no
 * `render()` override, so Livewire fell back to guessing a view path from
 * the class's own namespace segments — a guess resolved against the host's
 * `resources/views/livewire/*`, which never has these package views.
 * `Livewire::test()` alone would not have caught it (it still builds the
 * component and mounts it; the missing-view error only fires on render),
 * so each assertion below must force a render.
 *
 * `ConfirmPassword` and `VerifyEmail` (formerly `nvade/numerosis-auth-ui`'s)
 * were deleted, not moved, in Phase 3 of
 * `.claude/plans/archive/humming-nibbling-flame.md` — Phase 4 rebuilds both screens
 * on Fortify. `Appearance` (formerly `nvade/numerosis-account`'s) was a
 * starter-kit nicety, deleted outright the same phase.
 */
class SettingsAndAuthComponentViewsTest extends TestCase
{
    use RefreshDatabase;

    public function test_password_renders(): void
    {
        $user = CentralUser::factory()->create();
        $this->actingAs($user, Config::string('numerosis.auth.guards.central'));

        Livewire::test(Password::class)->assertStatus(200);
    }
}
