<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Database\Factories\Central;

use Illuminate\Database\Eloquent\Factories\Factory;
use Nvade\Numerosis\Enums\Billing\BillingCycle;
use Nvade\Numerosis\Enums\Tenancy\TenantProvisionStatus;
use Nvade\Numerosis\Models\Central\TenantProvision;

/** @extends Factory<TenantProvision> */
class TenantProvisionFactory extends Factory
{
    public function definition(): array
    {
        return [
            'slug' => $this->faker->unique()->slug(),
            'name' => $this->faker->company(),
            'global_id' => $this->faker->uuid(),
            'payment_plan' => null,
            'billing_cycle' => null,
            'stripe_setup_intent_id' => null,
            'stripe_subscription_id' => null,
            'status' => TenantProvisionStatus::Reserved,
        ];
    }

    public function forCheckout(string $paymentPlan, BillingCycle $billingCycle): static
    {
        return $this->state(fn (): array => [
            'payment_plan' => $paymentPlan,
            'billing_cycle' => $billingCycle,
        ]);
    }

    public function provisioning(): static
    {
        return $this->state(fn (): array => [
            'status' => TenantProvisionStatus::Provisioning,
            'provisioning_started_at' => now(),
        ]);
    }

    public function completed(): static
    {
        return $this->state(fn (): array => [
            'status' => TenantProvisionStatus::Completed,
            'provisioning_started_at' => now()->subMinute(),
            'completed_at' => now(),
            'settled_at' => now(),
        ]);
    }

    public function settled(): static
    {
        return $this->state(fn (): array => ['settled_at' => now()]);
    }

    public function failed(): static
    {
        return $this->state(fn (): array => [
            'status' => TenantProvisionStatus::Failed,
            'failed_at' => now(),
            'error' => $this->faker->sentence(),
        ]);
    }
}
