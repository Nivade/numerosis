<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Database\Factories\Central;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Nvade\Numerosis\Enums\Tenancy\ImpersonationEndReason;
use Nvade\Numerosis\Models\Central\CentralUser;
use Nvade\Numerosis\Models\Central\ImpersonationSession;
use Nvade\Numerosis\Models\Central\Tenant;

/** @extends Factory<ImpersonationSession> */
class ImpersonationSessionFactory extends Factory
{
    public function definition(): array
    {
        return [
            'token' => Str::random(128),
            'tenant_id' => Tenant::factory(),

            // `staff_global_id` references `users.global_id`, so the row has
            // to come from the user factory rather than a bare uuid.
            'staff_global_id' => CentralUser::factory()->create()->global_id,
            'target_global_id' => $this->faker->uuid(),
            'started_at' => null,
            'ended_at' => null,
            'ended_reason' => null,
        ];
    }

    public function redeemed(): static
    {
        return $this->state(fn (): array => ['started_at' => now()]);
    }

    public function stale(): static
    {
        return $this->state(fn (): array => [
            'started_at' => now()->subMinutes(ImpersonationSession::maximumMinutes() + 1),
        ]);
    }

    public function ended(): static
    {
        return $this->state(fn (): array => [
            'started_at' => now()->subMinutes(5),
            'ended_at' => now(),
            'ended_reason' => ImpersonationEndReason::Exit,
        ]);
    }
}
