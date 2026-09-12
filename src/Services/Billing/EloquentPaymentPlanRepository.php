<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Services\Billing;

use Illuminate\Support\Collection;
use Nvade\Numerosis\Cache\CacheKeys;
use Nvade\Numerosis\Cache\GlobalCache;
use Nvade\Numerosis\Contracts\Billing\PaymentPlanRepository;
use Nvade\Numerosis\Contracts\Billing\Plan;
use Nvade\Numerosis\Data\Billing\PlanFeature as PlanFeatureData;
use Nvade\Numerosis\Exceptions\Billing\PaymentPlanNotFound;
use Nvade\Numerosis\Models\Central\PaymentPlan;
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
     * The plan catalogue changes only when an operator edits it in an admin UI,
     * which busts this key through
     * {@see \Nvade\Numerosis\Observers\Billing\PaymentPlanObserver} and
     * {@see \Nvade\Numerosis\Observers\Billing\PaymentPlanFeatureObserver}, so the TTL
     * is only a backstop behind that invalidation.
     *
     * @return Collection<int, Plan>
     */
    public function available(): Collection
    {
        /** @var \Illuminate\Database\Eloquent\Collection<int, PaymentPlan> $plans */
        $plans = GlobalCache::store()->remember(
            CacheKeys::availablePaymentPlans(),
            now()->addHour(),
            fn () => Numerosis::model(PaymentPlan::class)::available()->with('features')->orderBy('monthly_price')->get()
        );

        // `Eloquent\Collection`'s own `TModel` template isn't covariant the way
        // `Support\Collection`'s `TValue` is, so it isn't assignable to the
        // interface's `Collection<int, Plan>` without an explicit rewrap; and
        // PHPStan doesn't credit `PaymentPlan implements Plan` at the
        // `Collection<PaymentPlan>` vs `Collection<Plan>` return boundary
        // either, so the rewrap needs its own narrowing.
        /** @var Collection<int, Plan> $result */
        $result = new Collection($plans->all());

        return $result;
    }

    /**
     * The plan/subscription tables are central data, so this must be computed
     * once for all tenants together: a plain Cache:: call, in tenant context,
     * is stancl's tenant-tagged manager, which both duplicates the computation
     * per tenant and requires a taggable cache store for something that has
     * nothing to do with any one tenant. Caches the slug directly rather than
     * the id, so there is no cache-round-trip type coercion to get wrong.
     */
    public function mostPopularSlug(): ?string
    {
        return GlobalCache::store()->remember(
            CacheKeys::popularPaymentPlanSlug(),
            now()->addMinutes(5),
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
     */
    public function featuresFor(Plan $plan): Collection
    {
        if (! $plan instanceof PaymentPlan) {
            return new Collection;
        }

        return $plan->features->map(fn (PlanFeature $feature): PlanFeatureData => new PlanFeatureData(
            slug: $feature->slug,
            name: $feature->name,
            description: $feature->description,
            available: (bool) $feature->pivot->available,
        ));
    }
}
