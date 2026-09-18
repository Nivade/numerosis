<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Services\Billing;

use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use Nvade\Numerosis\Cache\CacheKeys;
use Nvade\Numerosis\Cache\CacheTtl;
use Nvade\Numerosis\Cache\GlobalCache;
use Nvade\Numerosis\Contracts\Billing\PaymentPlanRepository;
use Nvade\Numerosis\Contracts\Billing\Plan;
use Nvade\Numerosis\Data\Billing\PlanFeature as PlanFeatureData;
use Nvade\Numerosis\Exceptions\Billing\PaymentPlanNotFound;
use Nvade\Numerosis\Models\Central\PaymentPlan;
use Nvade\Numerosis\Models\Central\PaymentPlanFeature;
use Nvade\Numerosis\Models\Central\PlanFeature;
use Nvade\Numerosis\Models\Central\Subscription;
use Nvade\Numerosis\Numerosis;

class EloquentPaymentPlanRepository implements PaymentPlanRepository
{
    /**
     * Scoped to available plans on purpose. Every checkout path resolves its
     * plan through here from a client-supplied slug, and existence is not
     * availability: an unscoped lookup lets anyone who remembers a retired
     * slug subscribe to it at its old price. Use {@see findAnyBySlug()} for
     * the admin/reporting paths that must still see retired plans.
     */
    public function findBySlug(string $slug): ?Plan
    {
        return Numerosis::model(PaymentPlan::class)::available()->firstWhere('slug', $slug);
    }

    public function findBySlugOrFail(string $slug): Plan
    {
        $plan = $this->findBySlug($slug);

        throw_unless($plan instanceof Plan, PaymentPlanNotFound::class, "Payment plan not found: {$slug}");

        return $plan;
    }

    public function findAnyBySlug(string $slug): ?Plan
    {
        return Numerosis::model(PaymentPlan::class)::firstWhere('slug', $slug);
    }

    public function findByPriceId(string $priceId): ?Plan
    {
        return Numerosis::model(PaymentPlan::class)::query()
            ->where('monthly_id', $priceId)
            ->orWhere('yearly_id', $priceId)
            ->first();
    }

    /**
     * Attribute rows are cached, never the models: a cached model carries its
     * eager-loaded `features` frozen at write time, and a host whose
     * `cache.serializable_classes` omits `PaymentPlan` reads back an
     * `__PHP_Incomplete_Class` with no exception and no log line.
     *
     * @see \Nvade\Numerosis\Observers\Billing\PaymentPlanObserver
     * @see \Nvade\Numerosis\Observers\Billing\PaymentPlanFeatureObserver
     *
     * @return Collection<int, Plan>
     */
    public function available(): Collection
    {
        $rows = GlobalCache::flexible(
            CacheKeys::availablePaymentPlans(),
            CacheTtl::window(CacheTtl::availablePaymentPlans()),
            fn (): array => $this->availablePlanRows()
        );

        /** @var Collection<int, Plan> $result */
        $result = new Collection(array_map($this->hydratePlan(...), $rows));

        return $result;
    }

    /**
     * @return list<array{plan: array<string, mixed>, features: list<array{attributes: array<string, mixed>, pivot: array<string, mixed>}>}>
     */
    private function availablePlanRows(): array
    {
        $plans = Numerosis::model(PaymentPlan::class)::available()
            ->with('features')
            ->orderBy('monthly_price')
            ->get()
            ->all();

        return array_values(array_map(fn (PaymentPlan $plan): array => [
            'plan' => $plan->getAttributes(),
            'features' => array_values(array_map(
                fn (PlanFeature $feature): array => [
                    'attributes' => $feature->getAttributes(),
                    'pivot' => $feature->pivot->getAttributes(),
                ],
                $plan->features->all(),
            )),
        ], $plans));
    }

    /**
     * @param  array{plan: array<string, mixed>, features: list<array{attributes: array<string, mixed>, pivot: array<string, mixed>}>}  $row
     */
    private function hydratePlan(array $row): PaymentPlan
    {
        $planClass = Numerosis::model(PaymentPlan::class);

        $plan = (new $planClass)->newFromBuilder($row['plan']);

        $features = array_map(
            function (array $feature) use ($plan): PlanFeature {
                $model = (new PlanFeature)->newFromBuilder($feature['attributes']);

                $model->setRelation('pivot', PaymentPlanFeature::fromRawAttributes(
                    $plan,
                    $feature['pivot'],
                    'payment_plan_features',
                    true,
                ));

                return $model;
            },
            $row['features'],
        );

        $plan->setRelation('features', new EloquentCollection($features));

        return $plan;
    }

    /**
     * The plan tables are central data, computed once for all tenants
     * together: a plain `Cache::` call in tenant context is stancl's
     * tenant-tagged manager, which duplicates the computation per tenant and
     * demands a taggable store. Caches the slug instead of the id, so no
     * cache-round-trip coercion can get it wrong.
     *
     * @see \Nvade\Numerosis\Observers\Billing\SubscriptionObserver
     */
    public function mostPopularSlug(): ?string
    {
        return GlobalCache::flexible(
            CacheKeys::popularPaymentPlanSlug(),
            CacheTtl::window(CacheTtl::popularPaymentPlanSlug()),
            function (): ?string {
                $popularPlanId = Numerosis::model(Subscription::class)::select('payment_plan_id')
                    ->selectRaw('COUNT(*) AS plan_count')
                    ->groupBy('payment_plan_id')
                    ->orderByDesc('plan_count')
                    ->first()?->payment_plan_id;

                if ($popularPlanId === null) {
                    return null;
                }

                return Numerosis::model(PaymentPlan::class)::find($popularPlanId)?->slug;
            }
        );
    }

    /**
     * @return Collection<int, PlanFeatureData>
     *
     * @throws InvalidArgumentException
     */
    public function featuresFor(Plan $plan): Collection
    {
        if (! $plan instanceof PaymentPlan) {
            throw new InvalidArgumentException("Payment plan is not stored locally: {$plan->slug()}");
        }

        return $plan->features->map(fn (PlanFeature $feature): PlanFeatureData => new PlanFeatureData(
            slug: $feature->slug,
            name: $feature->name,
            description: $feature->description,
            available: (bool) $feature->pivot->available,
        ));
    }
}
