<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Database\Factories\Central;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Fortify\Contracts\TwoFactorAuthenticationProvider;
use Laravel\Fortify\Fortify;
use Laravel\Fortify\RecoveryCode;
use Nvade\Numerosis\Database\Factories\Concerns\GeneratesUniqueEmails;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\Nvade\Numerosis\Models\Central\CentralUser>
 */
// No `protected $model` override: CentralUser is abstract, and a hardcoded
// $model bypasses Numerosis::modelNameFor()'s resolver, so Eloquent
// instantiates the abstract class and throws.
class CentralUserFactory extends Factory
{
    use GeneratesUniqueEmails;

    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => static::uniqueEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
            'global_id' => (string) Str::uuid(),
        ];
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }

    /**
     * A confirmed authenticator, so the account satisfies every gate that
     * reads `hasEnabledTwoFactorAuthentication()`.
     */
    public function withTwoFactor(?string $secret = null): static
    {
        return $this->state(fn (array $attributes) => [
            'two_factor_secret' => Fortify::currentEncrypter()->encrypt(
                $secret ?? resolve(TwoFactorAuthenticationProvider::class)->generateSecretKey()
            ),
            'two_factor_recovery_codes' => Fortify::currentEncrypter()->encrypt((string) json_encode(
                Collection::times(8, fn (): string => RecoveryCode::generate())->all()
            )),
            'two_factor_confirmed_at' => now(),
        ]);
    }

    /** A secret the user never proved they could produce a code from. */
    public function withUnconfirmedTwoFactor(): static
    {
        return $this->withTwoFactor()->state(fn (array $attributes) => [
            'two_factor_confirmed_at' => null,
        ]);
    }
}
