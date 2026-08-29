<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Models\Central;

use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;
use Laravel\Cashier\SubscriptionItem;
use Nvade\Numerosis\Database\Factories\Central\SubscriptionFactory;
use Nvade\Numerosis\Policies\SubscriptionPolicy;
use Nvade\Numerosis\Support\Numerosis;
use Override;
use Stancl\Tenancy\Database\Concerns\CentralConnection;

/**
 * @property int $id
 * @property int|null $user_id
 * @property string|null $subscribable_id
 * @property string|null $subscribable_type
 * @property string $type
 * @property int|null $payment_plan_id
 * @property string $stripe_id
 * @property string $stripe_status
 * @property string|null $stripe_price
 * @property int|null $quantity
 * @property Carbon|null $trial_ends_at
 * @property Carbon|null $ends_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Tenant $owner
 * @property-read Collection<int, SubscriptionItem> $items
 * @property-read int|null $items_count
 */
#[UseFactory(SubscriptionFactory::class)]
#[UsePolicy(SubscriptionPolicy::class)]
class Subscription extends \Laravel\Cashier\Subscription
{
    use CentralConnection;

    #[Override]
    protected function casts(): array
    {
        return [
            'trial_ends_at' => 'datetime',
            'ends_at' => 'datetime',
        ];
    }

    /** @var list<string> */
    protected $with = ['items', 'subscribable'];

    /**
     * @return BelongsTo<PaymentPlan, $this>
     */
    public function paymentPlan(): BelongsTo
    {
        return $this->belongsTo(Numerosis::model(PaymentPlan::class), 'payment_plan_id');
    }

    /**
     * @return SubscriptionFactory
     */
    #[Override]
    protected static function newFactory(): SubscriptionFactory|Factory
    {
        return SubscriptionFactory::new();
    }

    /**
     * Get the model related to the subscription.
     *
     * @return MorphTo<Model, $this>
     */
    public function subscribable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Get the model related to the subscription (Cashier override).
     *
     * @return MorphTo<Model, $this>
     */
    #[Override]
    public function user(): MorphTo
    {
        return $this->subscribable();
    }

    /**
     * @return BelongsTo<Model, $this>
     */
    #[Override]
    public function owner(): BelongsTo
    {
        /** @var class-string<Model> $ownerType */
        $ownerType = $this->getAttribute('subscribable_type');

        return $this->belongsTo($ownerType, 'subscribable_id');
    }
}
