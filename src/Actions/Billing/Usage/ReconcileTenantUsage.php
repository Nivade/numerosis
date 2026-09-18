<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Actions\Billing\Usage;

use Illuminate\Support\Facades\Log;
use Lorisleiva\Actions\Concerns\AsAction;
use Nvade\Numerosis\Actions\Queries\GetBillingPeriod;
use Nvade\Numerosis\Actions\Queries\GetTenantMeters;
use Nvade\Numerosis\Cache\CacheKeys;
use Nvade\Numerosis\Cache\GlobalCache;
use Nvade\Numerosis\Contracts\Billing\UsageCounter;
use Nvade\Numerosis\Data\Billing\BillingPeriod;
use Nvade\Numerosis\Data\Billing\MeterDefinitionData;
use Nvade\Numerosis\Events\Billing\UsageDivergenceDetected;
use Nvade\Numerosis\Models\Central\Tenant;
use Throwable;

/**
 * Compares what was reported locally against Stripe's own meter summaries for
 * one tenant. Never writes a correction: a silent adjustment to a number a
 * customer is invoiced against is worse than an operator reading two numbers.
 *
 * @method static array{warnings: list<string>, diverged: int} run(Tenant $tenant, int $tolerance)
 */
class ReconcileTenantUsage
{
    use AsAction;

    public function __construct(private readonly UsageCounter $counter) {}

    /** @return array{warnings: list<string>, diverged: int} */
    public function handle(Tenant $tenant, int $tolerance): array
    {
        $meters = GetTenantMeters::run($tenant);
        $period = GetBillingPeriod::run($tenant);

        $warnings = [];
        $diverged = 0;

        if ($meters->isEmpty() || ! $period instanceof BillingPeriod || $tenant->stripe_id === null) {
            return ['warnings' => $warnings, 'diverged' => $diverged];
        }

        foreach ($meters as $meter) {
            [$warning, $isDivergence] = $this->check($tenant, $meter, $period, $tolerance);

            if ($warning !== null) {
                $warnings[] = $warning;
            }

            if ($isDivergence) {
                $diverged++;
            }
        }

        return ['warnings' => $warnings, 'diverged' => $diverged];
    }

    /** @return array{0: ?string, 1: bool} A warning line (or null), and whether it counts as a divergence. */
    private function check(Tenant $tenant, MeterDefinitionData $meter, BillingPeriod $period, int $tolerance): array
    {
        if ($meter->meter_id === null) {
            return ["Plan meter [{$meter->event_name}] declares no meter_id, so Stripe holds no summary to compare against.", false];
        }

        $local = $this->counter->reported($tenant, $meter->key, $period->bucket());
        $remote = $this->stripeTotal($tenant, $meter, $period);

        if ($remote === null || abs($local - $remote) <= $tolerance) {
            return [null, false];
        }

        return [$this->reportDivergence($tenant, $meter, $period, $local, $remote), true];
    }

    /** Null when Stripe could not be read: an outage is not a divergence. */
    private function stripeTotal(Tenant $tenant, MeterDefinitionData $meter, BillingPeriod $period): ?int
    {
        try {
            return (int) $tenant->meterEventSummaries(
                (string) $meter->meter_id,
                $period->start->getTimestamp(),
                $period->end->getTimestamp(),
            )->sum(self::aggregatedValue(...));
        } catch (Throwable $e) {
            report($e);

            return null;
        }
    }

    /** Stripe's summary objects are untyped, so the value is read defensively. */
    private static function aggregatedValue(mixed $summary): int
    {
        $value = is_object($summary) ? ($summary->aggregated_value ?? null) : null;

        return is_numeric($value) ? (int) $value : 0;
    }

    /** Once per tenant, meter and period, however often the schedule runs. */
    private function reportDivergence(Tenant $tenant, MeterDefinitionData $meter, BillingPeriod $period, int $local, int $remote): string
    {
        $periodStart = $period->bucket()->toDateString();

        $warning = "Tenant [{$tenant->getTenantKey()}] reported {$local} of [{$meter->event_name}] for {$periodStart}; Stripe holds {$remote}.";

        $first = GlobalCache::claim(
            CacheKeys::usageDivergenceAlert((string) $tenant->getTenantKey(), $meter->event_name, $periodStart),
            $period->end,
        );

        if (! $first) {
            return $warning;
        }

        Log::error('Usage diverged from Stripe', [
            'tenant_id' => $tenant->getTenantKey(),
            'event_name' => $meter->event_name,
            'period_start' => $periodStart,
            'local' => $local,
            'stripe' => $remote,
        ]);

        event(new UsageDivergenceDetected($tenant, $meter->event_name, $periodStart, $local, $remote));

        return $warning;
    }
}
