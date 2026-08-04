<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Tests\Feature\Livewire\Auth;

use Nvade\Numerosis\Features\Turnstile\TurnstileFeature;
use Nvade\Numerosis\Livewire\Auth\ResetPassword;
use Nvade\Numerosis\Models\Central\CentralUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Password;
use Livewire\Livewire;
use RyanChandler\LaravelCloudflareTurnstile\Facades\Turnstile;
use Nvade\Numerosis\Tests\TestCase;

class ResetPasswordTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_resets_the_password_without_a_turnstile_token_when_disabled(): void
    {
        TurnstileFeature::forceForTesting(false);

        $user = CentralUser::factory()->create();
        $token = Password::createToken($user);

        Livewire::test(ResetPassword::class, ['token' => $token])
            ->set('email', $user->email)
            ->set('password', 'new-password')
            ->set('password_confirmation', 'new-password')
            ->call('resetPassword')
            ->assertHasNoErrors();
    }

    public function test_it_rejects_the_reset_without_a_turnstile_token_when_enabled(): void
    {
        TurnstileFeature::forceForTesting(true);
        Turnstile::fake()->fail();

        $user = CentralUser::factory()->create();
        $token = Password::createToken($user);

        Livewire::test(ResetPassword::class, ['token' => $token])
            ->set('email', $user->email)
            ->set('password', 'new-password')
            ->set('password_confirmation', 'new-password')
            ->call('resetPassword')
            ->assertHasErrors(['turnstileResponse']);
    }

    public function test_it_resets_the_password_with_a_valid_turnstile_token_when_enabled(): void
    {
        TurnstileFeature::forceForTesting(true);
        Turnstile::fake();

        $user = CentralUser::factory()->create();
        $token = Password::createToken($user);

        Livewire::test(ResetPassword::class, ['token' => $token])
            ->set('email', $user->email)
            ->set('password', 'new-password')
            ->set('password_confirmation', 'new-password')
            ->set('turnstileResponse', Turnstile::dummy())
            ->call('resetPassword')
            ->assertHasNoErrors();
    }
}
