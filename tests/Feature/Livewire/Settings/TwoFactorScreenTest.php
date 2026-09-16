<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Tests\Feature\Livewire\Settings;

use App\Models\Central\CentralUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Nvade\Numerosis\Livewire\Settings\TwoFactor;
use Nvade\Numerosis\Models\Central\CentralUser as BaseCentralUser;
use Nvade\Numerosis\Tests\TestCase;

class TwoFactorScreenTest extends TestCase
{
    use RefreshDatabase;

    /**
     * `confirm => true` is what makes the secret inert until the user has
     * produced a code from it, so enabling alone must not read as enrolled.
     */
    public function test_enabling_leaves_the_factor_unconfirmed(): void
    {
        $user = $this->signedInUser();

        Livewire::test(TwoFactor::class)
            ->call('enable')
            ->assertSet('confirming', true);

        $user->refresh();

        $this->assertNotNull($user->two_factor_secret);
        $this->assertNull($user->two_factor_confirmed_at);
        $this->assertFalse($user->hasEnabledTwoFactorAuthentication());
    }

    public function test_confirming_with_a_current_code_enrols_and_shows_recovery_codes(): void
    {
        $user = $this->signedInUser();

        $component = Livewire::test(TwoFactor::class)->call('enable');

        $component->set('code', $this->currentTwoFactorCode($user->refresh()))
            ->call('confirm')
            ->assertSet('showingRecoveryCodes', true)
            ->assertDispatched('two-factor-confirmed');

        $this->assertTrue($user->refresh()->hasEnabledTwoFactorAuthentication());
    }

    public function test_confirming_with_a_wrong_code_does_not_enrol(): void
    {
        $user = $this->signedInUser();

        Livewire::test(TwoFactor::class)
            ->call('enable')
            ->set('code', '000000')
            ->call('confirm')
            ->assertHasErrors('code');

        $this->assertNull($user->refresh()->two_factor_confirmed_at);
        $this->assertFalse($user->refresh()->hasEnabledTwoFactorAuthentication());
    }

    public function test_disabling_clears_every_column(): void
    {
        $user = $this->withConfirmedTwoFactor($this->signedInUser());

        Livewire::test(TwoFactor::class)
            ->call('disable')
            ->assertDispatched('two-factor-disabled');

        $user->refresh();

        $this->assertNull($user->two_factor_secret);
        $this->assertNull($user->two_factor_recovery_codes);
        $this->assertNull($user->two_factor_confirmed_at);
    }

    public function test_regenerating_replaces_the_recovery_codes(): void
    {
        $user = $this->withConfirmedTwoFactor($this->signedInUser());
        $before = $user->recoveryCodes();

        Livewire::test(TwoFactor::class)->call('regenerateRecoveryCodes');

        $this->assertNotEquals($before, $user->refresh()->recoveryCodes());
    }

    private function signedInUser(): BaseCentralUser
    {
        /** @var BaseCentralUser $user */
        $user = CentralUser::factory()->create();

        $this->actingAsCentralUser($user);

        return $user;
    }
}
