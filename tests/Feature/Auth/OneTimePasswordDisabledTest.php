<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Tests\Feature\Auth;

use App\Models\Central\CentralUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Nvade\Numerosis\Tests\TestCase;

/**
 * The other half of `OneTimePasswordLoginTest`: `OneTimePasswordFeature` is
 * **off by default** (it is commented out of `config/numerosis/features.php`),
 * so this class needs no setup at all — it is the shipped configuration.
 *
 * Worth its own file because Phase 5 changed two things that a passing OTP
 * suite says nothing about: `NumerosisLoginRequest` is bound over Fortify's
 * own `LoginRequest` for every request, feature or no feature, and
 * `Fortify::authenticateThrough()` was replaced wholesale. Both would still
 * look green with password login broken.
 */
class OneTimePasswordDisabledTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_registers_no_challenge_routes_when_disabled(): void
    {
        $this->assertFalse(Route::has('one-time-password.login'));
        $this->assertFalse(Route::has('one-time-password.login.store'));
    }

    public function test_password_login_still_authenticates(): void
    {
        $user = CentralUser::factory()->create();

        $response = $this->post(route('login.store'), [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $response->assertRedirect();
        $this->assertAuthenticatedAs($user);
    }

    /**
     * `NumerosisLoginRequest` relaxes `password` to `sometimes` only while the
     * feature is on. If that condition ever inverts, every login becomes an
     * email-only login that falls through to `AttemptToAuthenticate` with no
     * credential — this is the assertion that notices.
     */
    public function test_the_password_stays_required_when_disabled(): void
    {
        $user = CentralUser::factory()->create();

        $this->post(route('login.store'), ['email' => $user->email])
            ->assertSessionHasErrors('password');

        $this->assertGuest();
    }

    public function test_the_login_screen_still_asks_for_a_password(): void
    {
        $this->get(route('login'))
            ->assertOk()
            ->assertSee('name="password"', escape: false);
    }
}
