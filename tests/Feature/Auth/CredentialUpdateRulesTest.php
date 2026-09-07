<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Tests\Feature\Auth;

use App\Models\Central\CentralUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Nvade\Numerosis\Actions\Auth\UpdateUserPassword;
use Nvade\Numerosis\Actions\Auth\UpdateUserProfile;
use Nvade\Numerosis\Livewire\Settings\Password as PasswordSettings;
use Nvade\Numerosis\Livewire\Settings\Profile as ProfileSettings;
use Nvade\Numerosis\Tests\TestCase;

/**
 * The Livewire settings screens and Fortify's `/user/*` controllers reach the
 * same two actions, and `UpdatePasswordData`/`UpdateProfileData` are the only
 * rule set either of them runs. Both entry points are asserted together here,
 * because the three ways this drifted before were all invisible from one side:
 * `::run()` skipping validation entirely, the components re-declaring rules
 * that disagreed with the Data objects, and uniqueness ignoring the primary
 * key rather than `global_id`.
 */
class CredentialUpdateRulesTest extends TestCase
{
    use RefreshDatabase;

    public function test_running_the_password_action_directly_still_validates(): void
    {
        $user = CentralUser::factory()->create(['password' => Hash::make('old-password')]);
        $this->actingAsCentralUser($user);

        try {
            UpdateUserPassword::run($user, [
                'current_password' => 'not-the-old-password',
                'password' => 'Str0ng-Passw0rd!',
                'password_confirmation' => 'Str0ng-Passw0rd!',
            ]);

            $this->fail('Expected a ValidationException for a wrong current password.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('current_password', $e->errors());
        }

        $this->assertTrue(Hash::check('old-password', (string) $user->fresh()?->password));
    }

    public function test_running_the_password_action_directly_enforces_strength(): void
    {
        $user = CentralUser::factory()->create(['password' => Hash::make('old-password')]);
        $this->actingAsCentralUser($user);

        $this->expectException(ValidationException::class);

        UpdateUserPassword::run($user, [
            'current_password' => 'old-password',
            'password' => 'short',
            'password_confirmation' => 'short',
        ]);
    }

    /**
     * A social-login-only account has no password to confirm. Both entry
     * points have to agree on that, or the screen offers something the
     * endpoint rejects.
     */
    public function test_a_passwordless_user_sets_a_first_password_on_both_paths(): void
    {
        $user = CentralUser::factory()->create(['password' => null]);
        $this->actingAsCentralUser($user);

        Livewire::test(PasswordSettings::class)
            ->set('password', 'Str0ng-Passw0rd!')
            ->set('password_confirmation', 'Str0ng-Passw0rd!')
            ->call('updatePassword')
            ->assertHasNoErrors();

        $this->assertTrue(Hash::check('Str0ng-Passw0rd!', (string) $user->fresh()?->password));

        $second = CentralUser::factory()->create(['password' => null]);
        $this->actingAsCentralUser($second);

        $this->put('/user/password', [
            'password' => 'Other-Str0ng-Pass!',
            'password_confirmation' => 'Other-Str0ng-Pass!',
        ])->assertSessionHasNoErrors();

        $this->assertTrue(Hash::check('Other-Str0ng-Pass!', (string) $second->fresh()?->password));
    }

    public function test_a_blank_name_is_rejected_on_both_paths(): void
    {
        $user = CentralUser::factory()->create();
        $this->actingAsCentralUser($user);

        Livewire::test(ProfileSettings::class)
            ->set('name', '')
            ->call('updateProfileInformation')
            ->assertHasErrors('name');

        $this->from('/settings/profile')->put('/user/profile-information', [
            'name' => '',
            'email' => $user->email,
        ])->assertSessionHasErrors('name');

        $this->assertNotSame('', $user->fresh()?->name);
    }

    public function test_a_taken_address_is_rejected_on_both_paths(): void
    {
        $taken = CentralUser::factory()->create();
        $user = CentralUser::factory()->create();
        $this->actingAsCentralUser($user);

        Livewire::test(ProfileSettings::class)
            ->set('email', $taken->email)
            ->call('updateProfileInformation')
            ->assertHasErrors('email');

        $this->from('/settings/profile')->put('/user/profile-information', [
            'name' => $user->name,
            'email' => $taken->email,
        ])->assertSessionHasErrors('email');

        $this->assertSame($user->email, $user->fresh()?->email);
    }

    /**
     * Uniqueness ignores `global_id`, so resubmitting an unchanged address is
     * not a collision with the user's own central row.
     */
    public function test_a_user_may_keep_its_own_address(): void
    {
        $user = CentralUser::factory()->create();
        $this->actingAsCentralUser($user);

        UpdateUserProfile::run($user, [
            'name' => 'Renamed',
            'email' => $user->email,
        ]);

        $this->assertSame('Renamed', $user->fresh()?->name);
    }
}
