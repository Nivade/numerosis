<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Tests\Feature\Livewire\Auth;

use App\Models\Central\CentralUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Nvade\Numerosis\Contracts\Auth\CreatesRegisteredUser;
use Nvade\Numerosis\Features\Turnstile\TurnstileFeature;
use Nvade\Numerosis\Livewire\Auth\Register;
use Nvade\Numerosis\Tests\TestCase;
use RyanChandler\LaravelCloudflareTurnstile\Facades\Turnstile;

class RegisterTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_registers_without_a_turnstile_token_when_disabled(): void
    {
        TurnstileFeature::forceForTesting(false);

        Livewire::test(Register::class)
            ->set('name', 'Jane Doe')
            ->set('email', 'jane@example.com')
            ->set('password', 'password')
            ->set('password_confirmation', 'password')
            ->call('register')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('users', ['email' => 'jane@example.com']);
    }

    public function test_it_rejects_registration_without_a_turnstile_token_when_enabled(): void
    {
        TurnstileFeature::forceForTesting(true);
        Turnstile::fake()->fail();

        Livewire::test(Register::class)
            ->set('name', 'Jane Doe')
            ->set('email', 'jane@example.com')
            ->set('password', 'password')
            ->set('password_confirmation', 'password')
            ->call('register')
            ->assertHasErrors(['turnstileResponse']);

        $this->assertDatabaseMissing('users', ['email' => 'jane@example.com']);
    }

    public function test_it_registers_with_a_valid_turnstile_token_when_enabled(): void
    {
        TurnstileFeature::forceForTesting(true);
        Turnstile::fake();

        Livewire::test(Register::class)
            ->set('name', 'Jane Doe')
            ->set('email', 'jane@example.com')
            ->set('password', 'password')
            ->set('password_confirmation', 'password')
            ->set('turnstileResponse', Turnstile::dummy())
            ->call('register')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('users', ['email' => 'jane@example.com']);
    }

    /**
     * A consumer rebinds this to change how the registering user is stored
     * (extra fields, an external identity provider) without forking Register.
     */
    public function test_a_consumer_can_override_how_the_registered_user_is_created(): void
    {
        TurnstileFeature::forceForTesting(false);

        $this->app->singleton(CreatesRegisteredUser::class, fn () => new class implements CreatesRegisteredUser
        {
            public function create(array $data): CentralUser
            {
                return CentralUser::create([
                    'name' => 'Overridden: '.$data['name'],
                    'email' => $data['email'],
                    'password' => Hash::make($data['password']),
                ]);
            }
        });

        Livewire::test(Register::class)
            ->set('name', 'Jane Doe')
            ->set('email', 'jane@example.com')
            ->set('password', 'password')
            ->set('password_confirmation', 'password')
            ->call('register')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('users', [
            'email' => 'jane@example.com',
            'name' => 'Overridden: Jane Doe',
        ]);
    }
}
