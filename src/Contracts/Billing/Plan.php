<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Contracts\Billing;

use Nvade\Numerosis\Enums\BillingCycle;

interface Plan
{
    public function slug(): string;

    public function name(): string;

    public function priceId(BillingCycle $cycle): ?string;

    /**
     * @return int|null Minor currency units (cents).
     */
    public function price(BillingCycle $cycle): ?int;

    public function trialDays(): ?int;

    /**
     * @return PlanMetadata
     */
    public function metadata(): array;
}
