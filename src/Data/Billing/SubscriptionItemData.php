<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Data\Billing;

use Spatie\LaravelData\Data;

class SubscriptionItemData extends Data
{
    public function __construct(
        public string $stripe_id,
        public string $stripe_product,
        public string $stripe_price,
        public ?int $quantity = null,
        public ?string $meter_id = null,
        public ?string $meter_event_name = null,
        public ?string $current_period_start = null,
        public ?string $current_period_end = null,
    ) {}
}
