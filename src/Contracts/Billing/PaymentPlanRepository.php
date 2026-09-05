<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Contracts\Billing;

use Illuminate\Support\Collection;

interface PaymentPlanRepository
{
    /**
     * Resolve a *selectable* plan. Implementations must exclude retired
     * plans: this is what every checkout path calls with a client-supplied
     * slug, so an unscoped lookup keeps retired plans purchasable.
     */
    public function findBySlug(string $slug): ?Plan;

    /**
     * {@see findBySlug()}, for the checkout paths that cannot continue without
     * a plan and all refuse an unknown slug identically.
     *
     * @throws \Nvade\Numerosis\Exceptions\Billing\PaymentPlanNotFound
     */
    public function findBySlugOrFail(string $slug): Plan;

    /**
     * Resolve a plan whether or not it is still selectable, for admin and
     * reporting paths that have to describe subscriptions on retired plans.
     */
    public function findAnyBySlug(string $slug): ?Plan;

    public function findByPriceId(string $priceId): ?Plan;

    /**
     * @return Collection<int, Plan>
     */
    public function available(): Collection;
}
