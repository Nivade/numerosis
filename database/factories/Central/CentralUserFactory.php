<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Database\Factories\Central;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Nvade\Numerosis\Database\Factories\Concerns\GeneratesUniqueEmails;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\Nvade\Numerosis\Models\Central\CentralUser>
 */
// No `protected $model` override: CentralUser is abstract. A hardcoded
// $model bypasses Numerosis::modelNameFor()'s global resolver, so `new
// static` inside Eloquent's create()/make() instantiates the abstract class
// and throws.
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
}
