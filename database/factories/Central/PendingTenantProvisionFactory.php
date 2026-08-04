<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Database\Factories\Central;

use Nvade\Numerosis\Enums\BillingCycle;
use Nvade\Numerosis\Enums\TenantProvisionStatus;
use Nvade\Numerosis\Models\Central\PendingTenantProvision;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<PendingTenantProvision> */
class PendingTenantProvisionFactory extends Factory
{
    protected $model = PendingTenantProvision::class;

    public function definition(): array
    {
        return [
            'domain' => $this->faker->unique()->slug(),
            'company_name' => $this->faker->company(),
            'global_id' => $this->faker->uuid(),
            'payment_plan' => null,
            'billing_cycle' => null,
            'stripe_setup_intent_id' => null,
            'stripe_subscription_id' => null,
            'status' => TenantProvisionStatus::Reserved,
        ];
    }

    public function forCheckout(string $paymentPlan, BillingCycle $billingCycle): self
    {
        return $this->state(fn (): array => [
            'payment_plan' => $paymentPlan,
            'billing_cycle' => $billingCycle,
        ]);
    }

    public function provisioning(): self
    {
        return $this->state(fn (): array => [
            'status' => TenantProvisionStatus::Provisioning,
        ]);
    }

    public function failed(): self
    {
        return $this->state(fn (): array => [
            'status' => TenantProvisionStatus::Failed,
            'failed_at' => now(),
            'error' => $this->faker->sentence(),
        ]);
    }
}
