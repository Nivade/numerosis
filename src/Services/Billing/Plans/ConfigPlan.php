<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Services\Billing\Plans;

use Nvade\Numerosis\Contracts\Billing\Plan;
use Nvade\Numerosis\Enums\Billing\BillingCycle;

class ConfigPlan implements Plan
{
    /**
     * @param  PlanMetadata  $data
     */
    public function __construct(private readonly array $data) {}

    public function slug(): string
    {
        return $this->data['slug'] ?? '';
    }

    public function name(): string
    {
        return $this->data['name'] ?? '';
    }

    public function priceId(BillingCycle $cycle): ?string
    {
        return $this->data[$cycle->priceIdLabel()] ?? null;
    }

    public function price(BillingCycle $cycle): ?int
    {
        return null;
    }

    public function trialDays(): ?int
    {
        return $this->data['trial_days'] ?? null;
    }

    /**
     * @return PlanMetadata
     */
    public function metadata(): array
    {
        return $this->data;
    }
}
