<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Database\Factories\Central;

use Illuminate\Database\Eloquent\Factories\Factory;
use Nvade\Numerosis\Database\Factories\Concerns\GeneratesUniqueEmails;
use Nvade\Numerosis\Enums\Tenancy\MembershipRole;
use Nvade\Numerosis\Models\Central\CentralUser;
use Nvade\Numerosis\Models\Central\Tenant;

/** @extends Factory<\Nvade\Numerosis\Models\Central\Invitation> */
class InvitationFactory extends Factory
{
    use GeneratesUniqueEmails;

    /**
     * Define the model's default state.
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'email' => static::uniqueEmail(),
            'role' => MembershipRole::Member,
            'invited_by_user_id' => CentralUser::factory(),
            'expires_at' => now()->addDays(7),
        ];
    }

    public function expired(): static
    {
        return $this->state(fn (): array => [
            'expires_at' => now()->subDay(),
        ]);
    }

    public function accepted(): static
    {
        return $this->state(fn (): array => [
            'accepted_at' => now(),
            'accepted_by_user_id' => CentralUser::factory(),
        ]);
    }
}
