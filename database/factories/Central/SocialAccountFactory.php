<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Database\Factories\Central;

use Illuminate\Database\Eloquent\Factories\Factory;
use Nvade\Numerosis\Enums\Auth\SocialProvider;
use Nvade\Numerosis\Models\Central\CentralUser;

/** @extends Factory<\Nvade\Numerosis\Models\Central\SocialAccount> */
class SocialAccountFactory extends Factory
{
    /**
     * Define the model's default state.
     */
    public function definition(): array
    {
        return [
            'user_id' => CentralUser::factory(),
            'provider' => $this->faker->randomElement(SocialProvider::cases()),
            'provider_id' => $this->faker->unique()->numerify('##########'),
            'name' => $this->faker->name(),
            'email' => $this->faker->safeEmail(),
            'avatar_url' => $this->faker->imageUrl(),
            'token' => $this->faker->uuid(),
            'refresh_token' => $this->faker->uuid(),
            'token_expires_at' => now()->addHour(),
        ];
    }
}
