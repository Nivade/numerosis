<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Models\Central;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Nvade\Numerosis\Numerosis;
use Override;
use Stancl\Tenancy\Database\Concerns\CentralConnection;

/**
 * One redemption, as Stripe reported it. A row here says a code was applied to
 * a subscription; whether the discount is still live is Stripe's answer, read
 * through {@see \Nvade\Numerosis\Actions\Queries\GetTenantDiscount}.
 *
 * @property int $id
 * @property string|null $tenant_id
 * @property string|null $global_id
 * @property string|null $stripe_subscription_id
 * @property string $code
 * @property string $stripe_promotion_code_id
 * @property string $stripe_coupon_id
 * @property int|null $percent_off
 * @property int|null $amount_off
 * @property string|null $currency
 * @property Carbon $applied_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Tenant|null $tenant
 *
 * @mixin Model
 */
#[Fillable([
    'tenant_id',
    'global_id',
    'stripe_subscription_id',
    'code',
    'stripe_promotion_code_id',
    'stripe_coupon_id',
    'percent_off',
    'amount_off',
    'currency',
    'applied_at',
])]
class AppliedPromotion extends Model
{
    use CentralConnection;

    #[Override]
    protected function casts(): array
    {
        return [
            'percent_off' => 'integer',
            'amount_off' => 'integer',
            'applied_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Numerosis::model(Tenant::class), 'tenant_id');
    }
}
