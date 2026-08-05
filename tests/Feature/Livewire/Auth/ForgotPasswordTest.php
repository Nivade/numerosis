<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Tests\Feature\Livewire\Auth;

use App\Models\Central\CentralUser;
use Illuminate\Auth\Notifications\ResetPassword as ResetPasswordNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Nvade\Numerosis\Features\Turnstile\TurnstileFeature;
use Nvade\Numerosis\Livewire\Auth\ForgotPassword;
use Nvade\Numerosis\Tests\TestCase;
use RyanChandler\LaravelCloudflareTurnstile\Facades\Turnstile;

class ForgotPasswordTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_sends_a_reset_link_without_a_turnstile_token_when_disabled(): void
    {
        TurnstileFeature::forceForTesting(false);
        Notification::fake();

        $user = CentralUser::factory()->create();

        Livewire::test(ForgotPassword::class)
            ->set('email', $user->email)
            ->call('sendPasswordResetLink')
            ->assertHasNoErrors();

        Notification::assertSentTo($user, ResetPasswordNotification::class);
    }

    public function test_it_rejects_the_request_without_a_turnstile_token_when_enabled(): void
    {
        TurnstileFeature::forceForTesting(true);
        Turnstile::fake()->fail();
        Notification::fake();

        $user = CentralUser::factory()->create();

        Livewire::test(ForgotPassword::class)
            ->set('email', $user->email)
            ->call('sendPasswordResetLink')
            ->assertHasErrors(['turnstileResponse']);

        Notification::assertNothingSent();
    }

    public function test_it_sends_a_reset_link_with_a_valid_turnstile_token_when_enabled(): void
    {
        TurnstileFeature::forceForTesting(true);
        Turnstile::fake();
        Notification::fake();

        $user = CentralUser::factory()->create();

        Livewire::test(ForgotPassword::class)
            ->set('email', $user->email)
            ->set('turnstileResponse', Turnstile::dummy())
            ->call('sendPasswordResetLink')
            ->assertHasNoErrors();

        Notification::assertSentTo($user, ResetPasswordNotification::class);
    }
}
