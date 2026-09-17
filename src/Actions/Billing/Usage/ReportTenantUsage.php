<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Actions\Billing\Usage;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Lorisleiva\Actions\Concerns\AsAction;
use Nvade\Numerosis\Actions\Queries\GetBillingPeriod;
use Nvade\Numerosis\Actions\Queries\GetTenantMeters;
use Nvade\Numerosis\Contracts\Billing\UsageCounter;
use Nvade\Numerosis\Data\Billing\BillingPeriod;
use Nvade\Numerosis\Data\Billing\MeterDefinitionData;
use Nvade\Numerosis\Models\Central\Tenant;

/**
 * The meter event identifier is derived from tenant, event name, period and the
 * cumulative total. A retried job recomputes the same identifier and Stripe
 * deduplicates on it. Generating one per attempt double-bills the customer.
 *
 * @method static int run(Tenant $tenant)
 */
class ReportTenantUsage
{
    use AsAction;

    public function __construct(private readonly UsageCounter $counter) {}

    /** @return int How many meter events were sent. */
    public function handle(Tenant $tenant): int
    {
        $meters = GetTenantMeters::run($tenant);

        if ($meters->isEmpty()) {
            return 0;
        }

        $period = GetBillingPeriod::run($tenant);

        if (! $period instanceof BillingPeriod || $tenant->stripe_id === null) {
            return 0;
        }

        $sent = 0;

        foreach ($meters as $meter) {
            $sent += $this->report($tenant, $meter, $period) ? 1 : 0;
        }

        return $sent;
    }

    /**
     * The order matters: send, then mark. Marking first loses the usage
     * outright if the call fails, while a failure after the send is recovered
     * by the next run recomputing the same identifier.
     */
    private function report(Tenant $tenant, MeterDefinitionData $meter, BillingPeriod $period): bool
    {
        $bucket = $period->bucket();
        $total = $this->counter->value($tenant, $meter->key, $bucket);
        $delta = $total - $this->counter->reported($tenant, $meter->key, $bucket);

        if ($delta <= 0) {
            return false;
        }

        $identifier = self::identifier($tenant, $meter->event_name, $bucket, $total);

        $tenant->reportMeterEvent($meter->event_name, $delta, ['identifier' => $identifier]);

        $this->counter->markReported($tenant, $meter->key, $total, $identifier, $bucket);

        Log::info('Reported usage to Stripe', [
            'tenant_id' => $tenant->getTenantKey(),
            'event_name' => $meter->event_name,
            'quantity' => $delta,
            'identifier' => $identifier,
        ]);

        return true;
    }

    /**
     * Stable across process restarts and across retries, because every input
     * is state rather than a clock or a random source.
     */
    public static function identifier(Tenant $tenant, string $eventName, Carbon $period, int $total): string
    {
        return 'nms_'.hash('sha256', implode('|', [
            (string) $tenant->getTenantKey(),
            $eventName,
            $period->toDateString(),
            (string) $total,
        ]));
    }
}
