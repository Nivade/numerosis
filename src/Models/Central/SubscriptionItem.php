<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Models\Central;

use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Nvade\Numerosis\Database\Factories\Central\SubscriptionItemFactory;
use Override;
use Stancl\Tenancy\Database\Concerns\CentralConnection;

/**
 * @property int $id
 * @property int $subscription_id
 * @property string $stripe_id
 * @property string $stripe_product
 * @property string $stripe_price
 * @property int|null $quantity
 * @property string|null $meter_id
 * @property string|null $meter_event_name
 * @property Carbon|null $current_period_start
 * @property Carbon|null $current_period_end
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Subscription $subscription
 *
 * @mixin Model
 */
#[UseFactory(SubscriptionItemFactory::class)]
class SubscriptionItem extends \Laravel\Cashier\SubscriptionItem
{
    use CentralConnection;

    #[Override]
    protected function casts(): array
    {
        return [
            'current_period_start' => 'datetime',
            'current_period_end' => 'datetime',
        ];
    }

    public function isMetered(): bool
    {
        return $this->meter_event_name !== null || $this->meter_id !== null;
    }

    #[Override]
    protected static function newFactory(): SubscriptionItemFactory
    {
        return SubscriptionItemFactory::new();
    }
}
