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
    protected static function newFactory(): SubscriptionItemFactory
    {
        return SubscriptionItemFactory::new();
    }
}
