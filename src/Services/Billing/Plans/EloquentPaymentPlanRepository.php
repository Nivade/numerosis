<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Services\Billing\Plans;

use Illuminate\Support\Collection;
use Nvade\Numerosis\Contracts\Billing\PaymentPlanRepository;
use Nvade\Numerosis\Contracts\Billing\Plan;
use Nvade\Numerosis\Models\Central\PaymentPlan;
use Nvade\Numerosis\Support\Cache\CacheKeys;
use Nvade\Numerosis\Support\Numerosis;

class EloquentPaymentPlanRepository implements PaymentPlanRepository
{
    /**
     * Scoped to available plans on purpose. Every checkout path resolves its
     * plan through here from a client-supplied slug, and existence is not
     * availability — an unscoped lookup lets anyone who remembers a retired
     * slug subscribe to it at its old price. Use {@see findAnyBySlug()} for
     * the admin/reporting paths that must still see retired plans.
     */
    public function findBySlug(string $slug): ?Plan
    {
        return Numerosis::model(PaymentPlan::class)::available()->firstWhere('slug', $slug);
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
     * The plan catalogue changes only when an operator edits it in Filament
     * (see {@see PaymentPlan::booted()} and
     * {@see \Nvade\Numerosis\Models\Central\PaymentPlanFeature::booted()}), so this is
     * cached with a bounded TTL as a backstop behind that invalidation.
     *
     * @return Collection<int, Plan>
     */
    public function available(): Collection
    {
        /** @var Collection<int, Plan> */
        return global_cache()->remember(
            CacheKeys::availablePaymentPlans(),
            now()->addHour(),
            fn () => Numerosis::model(PaymentPlan::class)::available()->orderBy('monthly_price')->get()
        );
    }
}
