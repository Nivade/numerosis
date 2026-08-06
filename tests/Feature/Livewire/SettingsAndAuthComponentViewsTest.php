<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Tests\Feature\Livewire;

use App\Models\Central\CentralUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Livewire\Livewire;
use Nvade\Numerosis\Livewire\Auth\ConfirmPassword;
use Nvade\Numerosis\Livewire\Auth\VerifyEmail;
use Nvade\Numerosis\Livewire\Settings\Appearance;
use Nvade\Numerosis\Livewire\Settings\Password;
use Nvade\Numerosis\Tests\TestCase;

/**
 * Regression for the convention-registration audit
 * (.claude/plans/package-extraction.md, step 2): these four classes had no
 * `render()` override, so Livewire fell back to guessing a view path from
 * the class's own namespace segments — a guess resolved against the host's
 * `resources/views/livewire/*`, which never has these package views.
 * `Livewire::test()` alone would not have caught it (it still builds the
 * component and mounts it; the missing-view error only fires on render),
 * so each assertion below must force a render.
 */
class SettingsAndAuthComponentViewsTest extends TestCase
{
    use RefreshDatabase;

    public function test_appearance_renders(): void
    {
        Livewire::test(Appearance::class)->assertStatus(200);
    }

    public function test_password_renders(): void
    {
        $user = CentralUser::factory()->create();
        $this->actingAs($user, Config::string('auth.defaults.guards.context.central'));

        Livewire::test(Password::class)->assertStatus(200);
    }

    public function test_confirm_password_renders(): void
    {
        Livewire::test(ConfirmPassword::class)->assertStatus(200);
    }

    public function test_verify_email_renders(): void
    {
        Livewire::test(VerifyEmail::class)->assertStatus(200);
    }
}
