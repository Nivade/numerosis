<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Models\Central;

use Illuminate\Database\Eloquent\Attributes\Appends;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Nvade\Numerosis\Contracts\Billing\Plan;
use Nvade\Numerosis\Database\Factories\Central\PaymentPlanFactory;
use Nvade\Numerosis\Enums\Billing\BillingCycle;
use Nvade\Numerosis\Numerosis;
use Nvade\Numerosis\Observers\Billing\PaymentPlanObserver;
use Nvade\Numerosis\Policies\Billing\PaymentPlanPolicy;
use Nvade\Numerosis\Support\Cache\CacheKeys;
use Nvade\Numerosis\Support\Cache\GlobalCache;
use Override;
use Stancl\Tenancy\Database\Concerns\CentralConnection;

/**
 * @property string $name The name of the payment plan.
 * @property string $slug The slug used for URL routing.
 * @property string $description A brief description of the payment plan.
 * @property string|null $monthly_id The Stripe price ID of the monthly pricing plan.
 * @property string|null $yearly_id The Stripe price ID of the yearly pricing plan.
 * @property int $trial_days The number of trial days available for the payment plan.
 * @property int $monthly_price The monthly price for the payment plan, in minor currency units (cents).
 * @property int $yearly_price The yearly price for the payment plan, in minor currency units (cents).
 * @property bool $is_popular Whether the plan is marked as popular.
 * @property PlanMetadata|null $metadata Additional metadata for the plan.
 * @property bool $available Whether the plan is available for selection.
 *
 * @method static Builder<static> available()
 */
#[Appends([
    'is_popular',
])]
#[Fillable([
    'name',
    'slug',
    'description',
    'monthly_id',
    'yearly_id',
    'trial_days',
    'monthly_price',
    'yearly_price',
    'available',
    'metadata',
])]
#[ObservedBy(PaymentPlanObserver::class)]
#[UsePolicy(PaymentPlanPolicy::class)]
class PaymentPlan extends Model implements Plan
{
    use CentralConnection;

    /** @use HasFactory<PaymentPlanFactory> */
    use HasFactory;

    use SoftDeletes;

    #[Override]
    protected function casts(): array
    {
        return [
            'available' => 'boolean',
            'metadata' => 'array',
            'monthly_price' => 'integer',
            'yearly_price' => 'integer',
        ];
    }

    /**
     * @return Attribute<bool, never>
     */
    protected function isPopular(): Attribute
    {
        return Attribute::get(fn () => $this->popular());
    }

    /**
     * @return BelongsToMany<PlanFeature, $this, PaymentPlanFeature>
     */
    public function features(): BelongsToMany
    {
        return $this->belongsToMany(
            PlanFeature::class,
            'payment_plan_features',
            'payment_plan_id',
            'feature_id'
        )
            ->using(PaymentPlanFeature::class)
            ->withPivot(['available']);
    }

    /**
     * @return HasMany<Subscription, $this>
     */
    public function subscriptions(): HasMany
    {
        return $this->hasMany(Numerosis::model(Subscription::class), 'payment_plan_id');
    }

    /**
     * @return BelongsToMany<PlanFeature, $this, PaymentPlanFeature>
     */
    public function availableFeatures(): BelongsToMany
    {
        return $this->features()->wherePivot('available', true);
    }

    public function slug(): string
    {
        return $this->slug;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function priceId(BillingCycle $cycle): ?string
    {
        return $this->getPriceId($cycle);
    }

    public function price(BillingCycle $cycle): ?int
    {
        return $this->getPrice($cycle);
    }

    public function trialDays(): ?int
    {
        return $this->trial_days;
    }

    /**
     * @return PlanMetadata
     */
    public function metadata(): array
    {
        return $this->metadata ?? [];
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    #[Scope]
    protected function available(Builder $query): Builder
    {
        return $query->where('available', true);
    }

    /**
     * The plan/subscription tables are central data, so this must be computed
     * once for all tenants together: a plain Cache:: call, in tenant context,
     * is stancl's tenant-tagged manager, which both duplicates the computation
     * per tenant and requires a taggable cache
     * store for something that has nothing to do with any one tenant.
     */
    public function popular(): bool
    {
        $popularPlanId = GlobalCache::store()->remember(
            CacheKeys::popularPaymentPlanId(),
            now()->addMinutes(5),
            fn () => Numerosis::model(Subscription::class)::select('payment_plan_id')
                ->selectRaw('COUNT(*) AS plan_count')
                ->groupBy('payment_plan_id')
                ->orderByDesc('plan_count')
                ->first()?->payment_plan_id
        );

        // The cache round-trip loses the int type the query returns: the
        // driver hands back a string, so a strict `===` against the
        // Eloquent-cast `$this->id` was silently always false.
        return $popularPlanId !== null && (int) $popularPlanId === $this->id;
    }

    public function getPriceId(BillingCycle $cycle): ?string
    {
        return $cycle === BillingCycle::Monthly
            ? $this->monthly_id
            : $this->yearly_id;
    }

    public function getPrice(BillingCycle $cycle): ?int
    {
        return $cycle === BillingCycle::Monthly
            ? $this->monthly_price
            : $this->yearly_price;
    }

    public function getSavingsPercentage(): int
    {
        $monthly = $this->monthly_price;
        $yearly = $this->yearly_price;

        if ($monthly <= 0 || $yearly <= 0) {
            return 0;
        }

        $monthlyTotal = $monthly * 12;
        $savings = $monthlyTotal - $yearly;

        return (int) round(($savings / $monthlyTotal) * 100);
    }

    public function getIncentive(BillingCycle $cycle): ?string
    {
        return $this->metadata[$cycle->incentiveLabel()] ?? null;
    }
}
