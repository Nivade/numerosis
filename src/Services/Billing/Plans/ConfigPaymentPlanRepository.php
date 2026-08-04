<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Services\Billing\Plans;

use Nvade\Numerosis\Contracts\Billing\PaymentPlanRepository;
use Nvade\Numerosis\Contracts\Billing\Plan;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\Collection;

/**
 * Zero-migration plan source: reads billing.plans instead of the
 * payment_plans table. A consumer without the PaymentPlan migration binds
 * this in place of EloquentPaymentPlanRepository.
 */
class ConfigPaymentPlanRepository implements PaymentPlanRepository
{
    public function __construct(private readonly Repository $config) {}

    /**
     * Skips archived plans for the same reason its Eloquent counterpart
     * skips unavailable ones: the slug reaching here is client input, and a
     * retired plan must not stay purchasable by anyone who remembers it.
     */
    public function findBySlug(string $slug): ?Plan
    {
        $plan = $this->plans()->firstWhere('slug', $slug);

        if ($plan === null || ($plan['archived'] ?? false)) {
            return null;
        }

        return new ConfigPlan($plan);
    }

    public function findAnyBySlug(string $slug): ?Plan
    {
        $plan = $this->plans()->firstWhere('slug', $slug);

        return $plan ? new ConfigPlan($plan) : null;
    }

    public function findByPriceId(string $priceId): ?Plan
    {
        $plan = $this->plans()->first(
            fn (array $p): bool => ($p['monthly_id'] ?? null) === $priceId || ($p['yearly_id'] ?? null) === $priceId
        );

        return $plan ? new ConfigPlan($plan) : null;
    }

    /**
     * @return Collection<int, Plan>
     */
    public function available(): Collection
    {
        /** @var Collection<int, Plan> $plans */
        $plans = new Collection;

        foreach ($this->plans() as $p) {
            if ($p['archived'] ?? false) {
                continue;
            }

            $plans->push(new ConfigPlan($p));
        }

        return $plans;
    }

    /**
     * @return Collection<int, PlanMetadata>
     */
    private function plans(): Collection
    {
        /** @var array<int, PlanMetadata> $plans */
        $plans = $this->config->get('numerosis-billing.plans', []);

        return new Collection($plans);
    }
}
