<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Models\Central;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Nvade\Numerosis\Database\Factories\Central\FeatureFactory;
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
class Feature extends Model
{
    /**
     * `features` is created only by `database/migrations/central` — there is
     * no tenant copy — so this model must never ride the ambient connection,
     * which inside `$tenant->run()` points at a database where the table does
     * not exist. Every other `Models\Central\*` already declares it; this one
     * and its pivot were the two that did not.
     *
     * It is also what makes `PaymentPlanSeeder` runnable at all. Writing
     * `features` through the ambient default while `payment_plan_features`
     * (whose parent `PaymentPlan` *is* pinned) writes through `central` leaves
     * the freshly-inserted `features` row locked by the default connection's
     * open transaction, and the pivot insert's foreign-key check then blocks
     * on it for the full `innodb_lock_wait_timeout`. Deterministic, not flaky
     * — see `.claude/rules/testing.md`, which records the identical failure
     * for `RoleAndPermissionSeeder`.
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
