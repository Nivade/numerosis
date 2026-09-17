<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Services\Billing;

use Illuminate\Database\Query\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Nvade\Numerosis\Contracts\Billing\UsageCounter;
use Stancl\Tenancy\Contracts\Tenant;

/**
 * `increment()` on the row, never a read-modify-write in PHP: two workers
 * counting the same key at the same moment otherwise lose one of the two
 * increments, and a lost increment in a billing meter is money. The insert is
 * the race, and the unique index is what turns it into a retry.
 */
class DatabaseUsageCounter implements UsageCounter
{
    private const string TABLE = 'tenant_usage';

    public function increment(Tenant $tenant, string $key, int $by = 1, ?Carbon $period = null): int
    {
        if ((int) $this->scoped($tenant, $key, $period)->increment('value', $by) === 0) {
            $this->seed($tenant, $key, $by, $period);
        }

        return $this->value($tenant, $key, $period);
    }

    public function value(Tenant $tenant, string $key, ?Carbon $period = null): int
    {
        $value = $this->scoped($tenant, $key, $period)->value('value');

        return is_numeric($value) ? (int) $value : 0;
    }

    /**
     * @return array<string, int>
     */
    public function all(Tenant $tenant, ?Carbon $period = null): array
    {
        $query = $this->query()->where('tenant_id', (string) $tenant->getTenantKey());

        $this->constrainPeriod($query, $period);

        /** @var array<string, int> $values */
        $values = $query->pluck('value', 'key')
            ->map(fn (mixed $value): int => is_numeric($value) ? (int) $value : 0)
            ->all();

        return $values;
    }

    public function reset(Tenant $tenant, string $key, ?Carbon $period = null): void
    {
        $this->scoped($tenant, $key, $period)->delete();
    }

    public function reported(Tenant $tenant, string $key, ?Carbon $period = null): int
    {
        $value = $this->scoped($tenant, $key, $period)->value('reported_value');

        return is_numeric($value) ? (int) $value : 0;
    }

    /**
     * Never walks backwards: a late-arriving report of an older total must not
     * re-open usage Stripe already has, which would send it twice under a
     * second identifier.
     */
    public function markReported(Tenant $tenant, string $key, int $value, string $identifier, ?Carbon $period = null): void
    {
        $this->scoped($tenant, $key, $period)
            ->where('reported_value', '<', $value)
            ->update([
                'reported_value' => $value,
                'report_identifier' => $identifier,
                'reported_at' => now(),
                'updated_at' => now(),
            ]);
    }

    /**
     * Loses the race deliberately: whoever inserted first owns the row, and
     * this increment lands on theirs.
     */
    private function seed(Tenant $tenant, string $key, int $by, ?Carbon $period): void
    {
        try {
            $this->query()->insert([
                'tenant_id' => (string) $tenant->getTenantKey(),
                'key' => $key,
                'period_start' => $this->periodStart($period),
                'value' => $by,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (UniqueConstraintViolationException) {
            $this->scoped($tenant, $key, $period)->increment('value', $by);
        }
    }

    private function scoped(Tenant $tenant, string $key, ?Carbon $period): Builder
    {
        $query = $this->query()
            ->where('tenant_id', (string) $tenant->getTenantKey())
            ->where('key', $key);

        $this->constrainPeriod($query, $period);

        return $query;
    }

    /** `= null` matches no row, so a lifetime counter needs `whereNull`. */
    private function constrainPeriod(Builder $query, ?Carbon $period): void
    {
        $start = $this->periodStart($period);

        $start === null
            ? $query->whereNull('period_start')
            : $query->where('period_start', $start);
    }

    private function periodStart(?Carbon $period): ?string
    {
        return $period?->copy()->startOfDay()->toDateString();
    }

    private function query(): Builder
    {
        return DB::connection(Config::string('tenancy.database.central_connection', 'central'))->table(self::TABLE);
    }
}
