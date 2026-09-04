<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Database\Factories\Central;

use Illuminate\Database\Eloquent\Factories\Factory;
use Nvade\Numerosis\Enums\Billing\BillingCycle;
use Nvade\Numerosis\Enums\Tenancy\TenantProvisionStatus;
use Nvade\Numerosis\Models\Central\PendingTenantProvision;

// No `protected $model` override: PendingTenantProvision is abstract. A
// hardcoded $model bypasses Numerosis::modelNameFor()'s global resolver, so
// `new static` inside Eloquent's create()/make() instantiates the abstract
// class and throws.
/** @extends Factory<PendingTenantProvision> */
class PendingTenantProvisionFactory extends Factory
{
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
