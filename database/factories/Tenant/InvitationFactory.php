<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Database\Factories\Tenant;

use Nvade\Numerosis\Models\Central\Tenant;
use Nvade\Numerosis\Models\Tenant\Invitation;
use Nvade\Numerosis\Models\Tenant\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/** @extends \Illuminate\Database\Eloquent\Factories\Factory<\Nvade\Numerosis\Models\Tenant\Invitation> */
class InvitationFactory extends Factory
{
    protected $model = Invitation::class;

    /**
     * Define the model's default state.
     */
    public function definition(): array
    {
        return [
            'email' => $this->faker->unique()->safeEmail(),
            'role' => $this->faker->word(),
            'token' => Str::random(10),
            'expires_at' => Carbon::now(),
            'accepted_at' => Carbon::now(),
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),

            'invited_by' => User::factory(),
            // Inside tenant context the invitation belongs to the tenant whose
            // database it is being written to. Falling through to
            // Tenant::factory() there would provision a whole second tenant
            // (database included) for a foreign key that is only a string.
            'tenant_id' => tenancy()->initialized ? tenant()->getTenantKey() : Tenant::factory(),
        ];
    }
}
