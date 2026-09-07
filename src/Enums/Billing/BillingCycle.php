<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Enums\Billing;

enum BillingCycle: string
{
    case Monthly = 'monthly';
    case Yearly = 'yearly';

    /**
     * @return 'monthly_incentive'|'yearly_incentive'
     */
    public function incentiveLabel(): string
    {
        return match ($this) {
            self::Monthly => 'monthly_incentive',
            self::Yearly => 'yearly_incentive',
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Monthly => '/mo',
            self::Yearly => '/yr',
        };
    }
}
