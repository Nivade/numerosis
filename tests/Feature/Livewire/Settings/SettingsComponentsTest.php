<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Tests\Feature\Livewire\Settings;

use App\Models\Central\CentralUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Nvade\Numerosis\Livewire\Settings\Password;
use Nvade\Numerosis\Livewire\Settings\Profile;
use Nvade\Numerosis\Tests\TestCase;

/**
 * The settings screens stayed Livewire through Phase 4 while the guest auth
 * screens became plain Blade — see 4d of
 * `.claude/plans/humming-nibbling-flame.md`. Two things follow that are not
 * true of the guest screens and are asserted here:
 *
 * - they drive numerosis's Fortify actions rather than posting to a Fortify
 *   controller (Livewire posts every interaction to `/livewire/update`);
 * - their validation errors have to land in the **default** error bag.
 *   Fortify's own stock actions use `validateWithBag('updateProfileInformation')`,
 *   and a `flux:input` reads the default bag — so swapping one in silently
 *   renders no message at all. Asserting the *rendered* message, not just
 *   `assertHasErrors`, is what catches that.
 */
class SettingsComponentsTest extends TestCase
{
    use RefreshDatabase;

    public function test_profile_renders(): void
    {
        $this->actingAsCentralUser();

        Livewire::test(Profile::class)->assertStatus(200);
    }

    public function test_profile_updates_the_authenticated_user(): void
    {
        $user = $this->actingAsCentralUser();

        Livewire::test(Profile::class)
            ->set('name', 'Renamed Through Livewire')
            ->call('updateProfileInformation')
            ->assertHasNoErrors();

        $this->assertSame('Renamed Through Livewire', $user->fresh()?->name);
    }

    public function test_a_profile_validation_error_renders_from_the_default_bag(): void
    {
        $taken = CentralUser::factory()->create();

        $this->actingAsCentralUser();

        Livewire::test(Profile::class)
            ->set('email', $taken->email)
            ->call('updateProfileInformation')
            ->assertHasErrors('email')
            ->assertSee(__('validation.unique', ['attribute' => 'email']));
    }

    public function test_password_updates_through_the_fortify_action(): void
    {
        $user = $this->actingAsCentralUser();

        Livewire::test(Password::class)
            ->set('current_password', 'password')
            ->set('password', 'Str0ng-Passw0rd!')
            ->set('password_confirmation', 'Str0ng-Passw0rd!')
            ->call('updatePassword')
            ->assertHasNoErrors();

        $this->assertTrue(Hash::check('Str0ng-Passw0rd!', (string) $user->fresh()?->password));
    }

    public function test_a_wrong_current_password_renders_from_the_default_bag(): void
    {
        $this->actingAsCentralUser();

        Livewire::test(Password::class)
            ->set('current_password', 'not-the-password')
            ->set('password', 'Str0ng-Passw0rd!')
            ->set('password_confirmation', 'Str0ng-Passw0rd!')
            ->call('updatePassword')
            ->assertHasErrors('current_password');
    }

    private function actingAsCentralUser(): CentralUser
    {
        $user = CentralUser::factory()->create(['password' => Hash::make('password')]);

        $this->actingAs($user, Config::string('numerosis.auth.guards.central'));

        return $user;
    }
}
