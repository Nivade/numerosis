<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Services\Billing;

use Illuminate\Contracts\Config\Repository;
use Nvade\Numerosis\Contracts\Billing\Plan;
use Nvade\Numerosis\Contracts\Billing\TrialResolver;
use Nvade\Numerosis\Contracts\Subscribable;

class PlanOrDefaultTrialResolver implements TrialResolver
{
    public function __construct(private readonly Repository $config) {}

    public function daysFor(Plan $plan, ?Subscribable $for): ?int
    {
        /** @var int $default */
        $default = $this->config->get('numerosis.billing.trial_days', 0);

        return $plan->trialDays() ?? $default;
    }
}
