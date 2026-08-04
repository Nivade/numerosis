<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Database\Factories\Central;

use Nvade\Numerosis\Models\Central\Subscription;
use Nvade\Numerosis\Models\Central\SubscriptionItem;

class SubscriptionItemFactory extends \Laravel\Cashier\Nvade\Numerosis\Database\Factories\SubscriptionItemFactory
{
    protected $model = SubscriptionItem::class;

    /**
     * Define the model's default state.
     */
    public function definition(): array
    {
        return array_merge(parent::definition(), [
            'subscription_id' => Subscription::factory(),
        ]);
    }
}
