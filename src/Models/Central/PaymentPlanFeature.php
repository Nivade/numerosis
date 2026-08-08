<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Models\Central;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Attributes\WithoutTimestamps;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Nvade\Numerosis\Database\Factories\PaymentPlanFeatureFactory;
use Nvade\Numerosis\Observers\PaymentPlanFeatureObserver;
use Nvade\Numerosis\Support\Numerosis;
use Override;
use Stancl\Tenancy\Database\Concerns\CentralConnection;

/**
 * @property int $id
 * @property int $feature_id
 * @property int $payment_plan_id
 * @property bool $available
 * @property-read PaymentPlan $paymentPlan
 * @property-read Feature $feature
 *
 * @mixin Model
 */
#[Fillable([
    'feature_id',
    'payment_plan_id',
    'available',
])]
#[Table(name: 'payment_plan_features')]
#[WithoutTimestamps]
#[ObservedBy(PaymentPlanFeatureObserver::class)]
class PaymentPlanFeature extends Pivot
{
    /** @see Feature::$connection — same reasoning, pivot side. */
    use CentralConnection;

    /** @use HasFactory<PaymentPlanFeatureFactory> */
    use HasFactory;

    #[Override]
    protected function casts(): array
    {
        return [
            'available' => 'boolean',
        ];
    }

    /**
     * Get the payment plan that owns this feature.
     *
     * @return BelongsTo<PaymentPlan, $this>
     */
    public function paymentPlan(): BelongsTo
    {
        return $this->belongsTo(Numerosis::model(PaymentPlan::class));
    }

    /**
     * Get the feature for this pivot.
     *
     * @return BelongsTo<Feature, $this>
     */
    public function feature(): BelongsTo
    {
        return $this->belongsTo(Feature::class);
    }
}
