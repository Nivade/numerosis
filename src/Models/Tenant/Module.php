<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Models\Tenant;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Nvade\Numerosis\Enums\BillingCycle;
use Nvade\Numerosis\Policies\ModulePolicy;

/**
 * @property int $id
 * @property string $name
 * @property string $description
 * @property bool $enabled
 * @property Carbon|null $purchased_at
 * @property string|null $stripe_subscription_item_id
 * @property BillingCycle|null $billing_cycle
 * @property Carbon|null $migrated_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 *
 * @mixin Model
 */
#[UsePolicy(ModulePolicy::class)]
#[Fillable([
    'name',
    'description',
    'enabled',
    'purchased_at',
    'stripe_subscription_item_id',
    'billing_cycle',
    'migrated_at',
])]
class Module extends Model
{
    /** @use HasFactory<Factory<static>> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'purchased_at' => 'datetime',
            'billing_cycle' => BillingCycle::class,
            'migrated_at' => 'datetime',
        ];
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    #[Scope]
    protected function purchased(Builder $query): Builder
    {
        return $query->whereNotNull('purchased_at');
    }

    public function isPurchased(): bool
    {
        return $this->purchased_at !== null;
    }

    public function enable(): void
    {
        $this->update(['enabled' => true]);
    }

    public function disable(): void
    {
        $this->update(['enabled' => false]);
    }
}
