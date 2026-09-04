<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Enums\Billing;

enum BillingCycle: string
{
    case Monthly = 'monthly';
    case Yearly = 'yearly';

    /**
     * Written as a match, never "{$this->value}_id", so the result is a
     * literal type: callers index a typed plan-metadata array with it.
     *
     * @return 'monthly_id'|'yearly_id'
     */
    public function priceIdLabel(): string
    {
        return match ($this) {
            self::Monthly => 'monthly_id',
            self::Yearly => 'yearly_id',
        };
    }

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

    /**
     * @param  array<string, mixed>  $plan
     */
    public static function fromPriceId(string $priceId, array $plan): self|string
    {
        foreach (self::cases() as $cycle) {
            if (($plan[$cycle->priceIdLabel()] ?? null) === $priceId) {
                return $cycle;
            }
        }

        return 'unknown';
    }
}
