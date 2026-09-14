<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Database\Factories\Tenant;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Nvade\Numerosis\Database\Factories\Concerns\GeneratesUniqueEmails;
use Nvade\Numerosis\Models\Tenant\User;

/**
 * Laravel resolves a factory from the model's namespace, so
 * `Models\Tenant\User` looks for this class and nothing else. Without it
 * `User::factory()` inside tenant context fails at runtime only, since the
 * name is derived from a string rather than referenced in source.
 *
 * @extends Factory<User>
 */
// No `protected $model` override: User is abstract, and a hardcoded $model
// bypasses Numerosis::modelNameFor()'s resolver, so Eloquent instantiates the
// abstract class and throws.
class UserFactory extends Factory
{
    use GeneratesUniqueEmails;

    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            // Tenant users are synced from a central user by global_id, so a
            // value colliding across tenants cross-wires two users.
            'global_id' => (string) Str::uuid(),
            'email' => static::uniqueEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
            // `is_bot` is deliberately unset: saving a tenant user can make
            // stancl's listener create a central user from `getAttributes()`,
            // and central `users` has no such column.
        ];
    }

    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }
}
