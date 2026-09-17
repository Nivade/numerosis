<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Nvade\Numerosis\Actions\Queries\GetBillingPeriod;
use Nvade\Numerosis\Actions\Queries\GetTenantMeters;
use Nvade\Numerosis\Cache\CacheKeys;
use Nvade\Numerosis\Cache\GlobalCache;
use Nvade\Numerosis\Contracts\Billing\UsageCounter;
use Nvade\Numerosis\Data\Billing\BillingPeriod;
use Nvade\Numerosis\Data\Billing\MeterDefinitionData;
use Nvade\Numerosis\Events\Billing\UsageDivergenceDetected;
use Nvade\Numerosis\Models\Central\Tenant;
use Nvade\Numerosis\Numerosis;
use Throwable;

/**
 * Compares what was reported locally against Stripe's own meter summaries and
 * alerts on a gap. Never writes a correction: a silent adjustment to a number
 * a customer is invoiced against is worse than an operator reading two numbers.
 */
#[Description('Compare local usage counters against Stripe meter summaries and alert on divergence')]
#[Signature('billing:reconcile-usage
                            {--tenant=* : Tenant keys to check, defaulting to every metered tenant}
                            {--tolerance= : Units of difference to accept, defaulting to numerosis.billing.metering.tolerance}')]
class ReconcileUsage extends Command
{
    public function __construct(private readonly UsageCounter $counter)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $tolerance = $this->tolerance();
        $diverged = 0;

        foreach ($this->tenants() as $tenant) {
            $meters = GetTenantMeters::run($tenant);
            $period = GetBillingPeriod::run($tenant);

            if ($meters->isEmpty() || ! $period instanceof BillingPeriod || $tenant->stripe_id === null) {
                continue;
            }

            foreach ($meters as $meter) {
                $diverged += $this->check($tenant, $meter, $period, $tolerance) ? 1 : 0;
            }
        }

        if ($diverged > 0) {
            $this->components->warn("{$diverged} meter(s) diverged from Stripe beyond the tolerance.");

            return self::FAILURE;
        }

        $this->components->info('Every metered tenant agrees with Stripe.');

        return self::SUCCESS;
    }

    private function check(Tenant $tenant, MeterDefinitionData $meter, BillingPeriod $period, int $tolerance): bool
    {
        if ($meter->meter_id === null) {
            $this->components->warn("Plan meter [{$meter->event_name}] declares no meter_id, so Stripe holds no summary to compare against.");

            return false;
        }

        $local = $this->counter->reported($tenant, $meter->key, $period->bucket());
        $remote = $this->stripeTotal($tenant, $meter, $period);

        if ($remote === null || abs($local - $remote) <= $tolerance) {
            return false;
        }

        $this->reportDivergence($tenant, $meter, $period, $local, $remote);

        return true;
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
    private function reportDivergence(Tenant $tenant, MeterDefinitionData $meter, BillingPeriod $period, int $local, int $remote): void
    {
        $periodStart = $period->bucket()->toDateString();

        $this->components->warn("Tenant [{$tenant->getTenantKey()}] reported {$local} of [{$meter->event_name}] for {$periodStart}; Stripe holds {$remote}.");

        $first = GlobalCache::claim(
            CacheKeys::usageDivergenceAlert((string) $tenant->getTenantKey(), $meter->event_name, $periodStart),
            $period->end,
        );

        if (! $first) {
            return;
        }

        Log::error('Usage diverged from Stripe', [
            'tenant_id' => $tenant->getTenantKey(),
            'event_name' => $meter->event_name,
            'period_start' => $periodStart,
            'local' => $local,
            'stripe' => $remote,
        ]);

        event(new UsageDivergenceDetected($tenant, $meter->event_name, $periodStart, $local, $remote));
    }

    private function tolerance(): int
    {
        $option = $this->option('tolerance');

        return is_numeric($option)
            ? (int) $option
            : Config::integer('numerosis.billing.metering.tolerance', 0);
    }

    /**
     * @return Collection<int, Tenant>
     */
    private function tenants(): Collection
    {
        /** @var list<string> $keys */
        $keys = array_values(array_filter((array) $this->option('tenant'), is_string(...)));

        /** @var Collection<int, Tenant> $tenants */
        $tenants = Numerosis::model(Tenant::class)::query()
            ->whereNotNull('stripe_id')
            ->when($keys !== [], fn ($query) => $query->whereIn('id', $keys))
            ->get();

        return $tenants;
    }
}
