<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Data\Billing;

use Spatie\LaravelData\Data;
use Spatie\LaravelData\DataCollection;

class SubscriptionData extends Data
{
    /**
     * @param  DataCollection<int, SubscriptionItemData>  $items
     */
    public function __construct(
        public ?string $user_id,
        public ?string $payment_plan_id,
        public string $stripe_id,
        public string $stripe_status,
        public string $subscribable_id,
        public string $subscribable_type,
        public string $type = 'default',
        public ?string $stripe_price = null,
        public ?int $quantity = null,
        public ?string $trial_ends_at = null,
        public ?string $ends_at = null,
        public DataCollection $items = new DataCollection(SubscriptionItemData::class, []),
    ) {}
}
