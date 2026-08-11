<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Models\Central;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Nvade\Numerosis\Database\Factories\Central\FeatureFactory;
use Nvade\Numerosis\Policies\FeaturePolicy;
use Nvade\Numerosis\Support\Numerosis;
use Stancl\Tenancy\Database\Concerns\CentralConnection;

/**
 * @property int $id
 * @property string $slug
 * @property string $description
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read string $name
 * @property-read Collection<int, PaymentPlan> $paymentPlans
 * @property-read int|null $payment_plans_count
 *
 * @mixin Model
 */
#[Fillable([
    'slug',
    'description',
])]
#[UsePolicy(FeaturePolicy::class)]
class Feature extends Model
{
    /**
     * Pinned to the central connection, as every central model must be: the
     * `features` table exists only in the central database, so riding the
     * ambient connection inside tenant context queries a database that has
     * no such table.
     */
    use CentralConnection;

    /** @use HasFactory<FeatureFactory> */
    use HasFactory;

    /**
     * @return Attribute<string, never>
     */
    protected function name(): Attribute
    {
        return Attribute::get(
            fn () => Str::headline($this->slug)
        );
    }

    /**
     * Get the payment plans that have this feature.
     *
     * @return BelongsToMany<PaymentPlan, $this, PaymentPlanFeature>
     */
    public function paymentPlans(): BelongsToMany
    {
        return $this->belongsToMany(
            Numerosis::model(PaymentPlan::class),
            'payment_plan_features',
        )
            ->using(PaymentPlanFeature::class)
            ->withPivot(['available']);
    }
}
