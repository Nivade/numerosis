<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Actions\Billing;

use Lorisleiva\Actions\Concerns\AsAction;
use Nvade\Numerosis\Enums\Billing\BillingCycle;
use Nvade\Numerosis\Enums\Billing\PlanChangeDirection;
use Nvade\Numerosis\Facades\Billing;

/**
 * Whether a price change was an upgrade or a downgrade.
 *
 * Compared within whichever billing cycle the new price belongs to, so a
 * monthly figure is never weighed against a yearly one. Falls back to
 * `Upgrade` when either price resolves to no configured plan, since every
 * consumer of this is copy or a heuristic.
 *
 * @method static PlanChangeDirection run(string $fromPriceId, string $toPriceId)
 */
class ResolvePlanChangeDirection
{
    use AsAction;

    public function handle(string $fromPriceId, string $toPriceId): PlanChangeDirection
    {
        $from = Billing::planForPrice($fromPriceId);
        $to = Billing::planForPrice($toPriceId);

        $cycle = $to?->priceId(BillingCycle::Yearly) === $toPriceId
            ? BillingCycle::Yearly
            : BillingCycle::Monthly;

        $fromPrice = $from?->price($cycle);
        $toPrice = $to?->price($cycle);

        return $fromPrice !== null && $toPrice !== null && $toPrice < $fromPrice
            ? PlanChangeDirection::Downgrade
            : PlanChangeDirection::Upgrade;
    }
}
