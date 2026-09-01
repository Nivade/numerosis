<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Contracts\Billing;

use Nvade\Numerosis\Enums\Billing\BillingCycle;
use Nvade\Numerosis\Enums\Billing\ModuleBillingMode;

interface ModuleOffer
{
    public function slug(): string;

    public function name(): string;

    public function description(): ?string;

    public function billingMode(): ModuleBillingMode;

    /**
     * Ignored for a {@see ModuleBillingMode::OneTime} offer — pass null.
     */
    public function priceId(?BillingCycle $cycle): ?string;

    /**
     * @return int|null Minor currency units (cents).
     */
    public function price(?BillingCycle $cycle): ?int;
}
