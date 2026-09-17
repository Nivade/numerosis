<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Data\Api;

use Nvade\Numerosis\Data\Billing\MeterUsage;
use Spatie\LaravelData\Data;

/**
 * One meter's consumption this period. The period is flattened into two
 * timestamps because an integration reconciling against an invoice needs the
 * window, not the object modelling it.
 */
class UsageData extends Data
{
    public function __construct(
        public string $key,
        public int $used,
        public ?int $included,
        public int $overage,
        public string $period_start,
        public string $period_end,
    ) {}

    public static function fromMeter(MeterUsage $meter): self
    {
        return new self(
            key: $meter->key,
            used: $meter->used,
            included: $meter->included,
            overage: $meter->overage(),
            period_start: $meter->period->start->toIso8601String(),
            period_end: $meter->period->end->toIso8601String(),
        );
    }
}
