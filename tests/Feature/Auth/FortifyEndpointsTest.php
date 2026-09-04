<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Tests\Feature\Auth;

use App\Models\Central\CentralUser;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Nvade\Numerosis\Notifications\Auth\VerifyEmail;
use Nvade\Numerosis\Tests\TestCase;

/**
 * Phase 4 of `.claude/plans/humming-nibbling-flame.md` rebound four of this
 * package's actions onto Fortify's contracts —
 * `CreateRegisteredUser`/`UpdateUserProfile`/`UpdateUserPassword`/`ResetUserPassword`
 * — and every one of them is reached only through a Fortify controller. The
 * bindings themselves are one line each in `registerFortify()`, so what is
 * worth testing is that the request actually arrives at *this* package's
 * action rather than Fortify's stock one, which is only observable from
 * behaviour the stock action does not have.
 */
class FortifyEndpointsTest extends TestCase
{
    use RefreshDatabase;

    public function test_registering_creates_a_central_user(): void
    {
        $email = 'registered-'.uniqid().'@example.com';

        $this->post('/register', [
            'name' => 'New User',
            'email' => $email,
            'password' => 'Str0ng-Passw0rd!',
            'password_confirmation' => 'Str0ng-Passw0rd!',
        ])->assertRedirect();

        $user = CentralUser::firstWhere('email', $email);

        $this->assertNotNull($user);
        $this->assertTrue(Hash::check('Str0ng-Passw0rd!', (string) $user->password));
    }

    /**
     * Fortify's `RegisteredUserController` validates nothing itself — the
     * contract expects the action to. `RegistrationData::validateAndCreate()`
     * is what discharges that, and a duplicate address is the cheapest proof
     * the rules are running at all.
     */
    public function test_registering_validates_through_the_data_object(): void
    {
        $existing = CentralUser::factory()->create();

        $this->from('/register')->post('/register', [
            'name' => 'Duplicate',
            'email' => $existing->email,
            'password' => 'Str0ng-Passw0rd!',
            'password_confirmation' => 'Str0ng-Passw0rd!',
        ])->assertSessionHasErrors('email');
    }

    /**
     * The merge Phase 4b called for: numerosis's own `isDirty('email')` →
     * null `email_verified_at`, **plus** Fortify's re-send of the
     * verification notification, which core's pre-Fortify action omitted.
     */
    public function test_changing_the_email_unverifies_it_and_resends_the_notification(): void
    {
        Notification::fake();

        $user = CentralUser::factory()->create(['email_verified_at' => now()]);
        $this->actingAs($user, Config::string('numerosis.auth.guards.central'));

        $this->put('/user/profile-information', [
            'name' => $user->name,
            'email' => 'moved-'.uniqid().'@example.com',
        ])->assertSessionHasNoErrors();

        $user->refresh();

        $this->assertNull($user->email_verified_at);

        Notification::assertSentTo($user, VerifyEmail::class);
    }

    public function test_updating_the_name_alone_leaves_the_address_verified(): void
    {
        Notification::fake();

        $user = CentralUser::factory()->create(['email_verified_at' => now()]);
        $this->actingAs($user, Config::string('numerosis.auth.guards.central'));

        $this->put('/user/profile-information', [
            'name' => 'Renamed',
            'email' => $user->email,
        ])->assertSessionHasNoErrors();

        $user->refresh();

        $this->assertSame('Renamed', $user->name);
        $this->assertNotNull($user->email_verified_at);

        Notification::assertNotSentTo($user, VerifyEmail::class);
    }

    public function test_updating_the_password_requires_the_current_one(): void
    {
        $user = CentralUser::factory()->create(['password' => Hash::make('old-password')]);
        $this->actingAs($user, Config::string('numerosis.auth.guards.central'));

        $this->from('/settings/password')->put('/user/password', [
            'current_password' => 'not-the-old-password',
            'password' => 'Str0ng-Passw0rd!',
            'password_confirmation' => 'Str0ng-Passw0rd!',
        ])->assertSessionHasErrors('current_password');

        $this->assertTrue(Hash::check('old-password', (string) $user->fresh()?->password));
    }

    public function test_updating_the_password_replaces_it(): void
    {
        $user = CentralUser::factory()->create(['password' => Hash::make('old-password')]);
        $this->actingAs($user, Config::string('numerosis.auth.guards.central'));

        $this->put('/user/password', [
            'current_password' => 'old-password',
            'password' => 'Str0ng-Passw0rd!',
            'password_confirmation' => 'Str0ng-Passw0rd!',
        ])->assertSessionHasNoErrors();

        $this->assertTrue(Hash::check('Str0ng-Passw0rd!', (string) $user->fresh()?->password));
    }

    /**
     * End to end through both password-reset controllers and numerosis's
     * `ResetUserPassword`, using the token the notification actually carried
     * rather than one minted here — the token is what ties the two legs
     * together, and a reset that "works" against a hand-made token proves
     * nothing about the broker.
     */
    public function test_a_password_can_be_reset_end_to_end(): void
    {
        Notification::fake();

        $user = CentralUser::factory()->create(['password' => Hash::make('old-password')]);

        $this->post('/forgot-password', ['email' => $user->email])
            ->assertSessionHasNoErrors();

        $token = null;

        Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $notification) use (&$token): bool {
            $token = $notification->token;

            return true;
        });

        $this->assertIsString($token);

        $this->post('/reset-password', [
            'token' => $token,
            'email' => $user->email,
            'password' => 'Str0ng-Passw0rd!',
            'password_confirmation' => 'Str0ng-Passw0rd!',
        ])->assertSessionHasNoErrors();

        $this->assertTrue(Hash::check('Str0ng-Passw0rd!', (string) $user->fresh()?->password));

        // The token is single-use, which is the broker's job and not the
        // action's — worth pinning since numerosis supplies the action.
        $this->assertNotSame(
            Password::PASSWORD_RESET,
            Password::broker()->reset([
                'token' => $token,
                'email' => $user->email,
                'password' => 'Another-Str0ng-Pass!',
                'password_confirmation' => 'Another-Str0ng-Pass!',
            ], fn () => null),
        );
    }
}
