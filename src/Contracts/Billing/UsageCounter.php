<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Contracts\Billing;

use Illuminate\Support\Carbon;
use Stancl\Tenancy\Contracts\Tenant;

/**
 * Backed by the central database, never the cache. A counter that loses writes
 * on a cache flush under-bills. Any replacement bound through
 * `numerosis.billing.implementations` has to keep the increment atomic.
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
     * Cumulative instead of a delta, because the identifier derives from it
     * and that is what makes a retry a no-op on Stripe's side.
     */
    public function markReported(Tenant $tenant, string $key, int $value, string $identifier, ?Carbon $period = null): void;
}
