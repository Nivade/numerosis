<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Database\Factories\Tenant;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Nvade\Numerosis\Models\Tenant\User;

/**
 * Laravel resolves a factory from the model's namespace below `Nvade\Numerosis\Models`, so
 * `Nvade\Numerosis\Models\Tenant\User` looks for this class and nothing else — the root
 * `Database\Factories\UserFactory` builds a central user and never applied
 * here. Without it, `User::factory()` inside tenant context fails with
 * `Class "Database\Factories\Tenant\UserFactory" not found`, at runtime only,
 * because the name is derived from a string rather than referenced in source.
 *
 * @extends Factory<User>
 */
// No `protected $model` override: User is abstract. A hardcoded $model
// bypasses Numerosis::modelNameFor()'s global resolver, so `new static`
// inside Eloquent's create()/make() instantiates the abstract class and
// throws.
class UserFactory extends Factory
{
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
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
            // `is_bot` is deliberately not set. Saving a tenant user fires
            // SyncedResourceSaved, and when no central user carries the same
            // global_id yet, stancl's listener creates one from
            // `getAttributes()` — every attribute, not just the synced ones.
            // Central `users` has no `is_bot` column, so setting it here turns
            // an ordinary factory call into
            // "Unknown column 'is_bot' in 'field list' (Connection: central)".
            // The column defaults to false in the tenant schema anyway.
        ];
    }

    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }

    /**
     * The seeded Chat Bot occupies a real row in every tenant database and is
     * skipped by PromoteFirstUserToAdmin, so tests covering that need one.
     *
     * Only safe when a central user already carries this global_id — see the
     * note on `is_bot` in definition() for why an unsynced bot cannot be
     * created through the normal save path.
     */
    public function bot(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_bot' => true,
        ]);
    }
}
