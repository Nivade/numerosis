<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Models\Central;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Nvade\Numerosis\Contracts\Billing\ModuleOffer;
use Nvade\Numerosis\Database\Factories\Central\ModuleOfferingFactory;
use Nvade\Numerosis\Enums\BillingCycle;
use Nvade\Numerosis\Enums\ModuleBillingMode;
use Nvade\Numerosis\Observers\ModuleOfferingObserver;
use Nvade\Numerosis\Policies\ModuleOfferingPolicy;
use Override;
use Stancl\Tenancy\Database\Concerns\CentralConnection;

/**
 * @property string $name
 * @property string $slug
 * @property string|null $description
 * @property ModuleBillingMode $billing_mode
 * @property string|null $monthly_id
 * @property string|null $yearly_id
 * @property string|null $one_time_id
 * @property int|null $monthly_price
 * @property int|null $yearly_price
 * @property int|null $one_time_price
 * @property bool $available
 *
 * @method static Builder<static> available()
 */
#[Fillable([
    'name',
    'slug',
    'description',
    'billing_mode',
    'monthly_id',
    'yearly_id',
    'one_time_id',
    'monthly_price',
    'yearly_price',
    'one_time_price',
    'available',
])]
#[Table(name: 'modules')]
#[ObservedBy(ModuleOfferingObserver::class)]
#[UsePolicy(ModuleOfferingPolicy::class)]
class ModuleOffering extends Model implements ModuleOffer
{
    use CentralConnection;

    /** @use HasFactory<ModuleOfferingFactory> */
    use HasFactory;

    #[Override]
    protected function casts(): array
    {
        return [
            'billing_mode' => ModuleBillingMode::class,
            'available' => 'boolean',
            'monthly_price' => 'integer',
            'yearly_price' => 'integer',
            'one_time_price' => 'integer',
        ];
    }

    public function slug(): string
    {
        return $this->slug;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function description(): ?string
    {
        return $this->description;
    }

    public function billingMode(): ModuleBillingMode
    {
        return $this->billing_mode;
    }

    public function priceId(?BillingCycle $cycle): ?string
    {
        if ($this->billing_mode === ModuleBillingMode::OneTime) {
            return $this->one_time_id;
        }

        return $cycle === BillingCycle::Yearly ? $this->yearly_id : $this->monthly_id;
    }

    public function price(?BillingCycle $cycle): ?int
    {
        if ($this->billing_mode === ModuleBillingMode::OneTime) {
            return $this->one_time_price;
        }

        return $cycle === BillingCycle::Yearly ? $this->yearly_price : $this->monthly_price;
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
}
