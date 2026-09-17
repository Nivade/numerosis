<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Contracts\Billing;

use Illuminate\Support\Carbon;
use Stancl\Tenancy\Contracts\Tenant;

/**
 * One counter, read two ways: a quota asks how much of an allowance is gone,
 * a billing meter asks how much to charge for. Backed by the central database
 * rather than the cache — a counter that loses writes on a cache flush is a
 * counter that under-bills.
 *
 * Point `numerosis.billing.implementations` at your own class to count
 * somewhere else; keep the increment atomic if you do.
 */
interface UsageCounter
{
    /** @return int The value after the increment. */
    public function increment(Tenant $tenant, string $key, int $by = 1, ?Carbon $period = null): int;

    public function value(Tenant $tenant, string $key, ?Carbon $period = null): int;

    /**
     * Every counter the tenant holds for this period, keyed by usage key.
     *
     * @return array<string, int>
     */
    public function all(Tenant $tenant, ?Carbon $period = null): array;

    public function reset(Tenant $tenant, string $key, ?Carbon $period = null): void;

    /** How much of this counter Stripe has already been told about, cumulatively. */
    public function reported(Tenant $tenant, string $key, ?Carbon $period = null): int;

    /**
     * Records that usage up to `$value` reached Stripe under `$identifier`.
     * Cumulative rather than a delta, because the identifier derives from it
     * and that is what makes a retry a no-op on Stripe's side.
     */
    public function markReported(Tenant $tenant, string $key, int $value, string $identifier, ?Carbon $period = null): void;
}
