<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\LazyCollection;
use Nvade\Numerosis\Actions\Billing\Usage\ReportTenantUsage;
use Nvade\Numerosis\Actions\Queries\GetTenantMeters;
use Nvade\Numerosis\Models\Central\Tenant;
use Nvade\Numerosis\Numerosis;

/**
 * Queues a meter report per tenant. Safe to run twice: the identifier each
 * event carries is derived from the counter, so a second run within the same
 * period sends nothing Stripe has not already deduplicated.
 */
#[Description('Report tenant usage counters to Stripe as billing meter events')]
#[Signature('billing:report-usage
                            {--tenant=* : Tenant keys to report, defaulting to every metered tenant}
                            {--sync : Report in this process instead of queueing}')]
class ReportUsage extends Command
{
    public function handle(): int
    {
        $queued = 0;
        $sent = 0;

        foreach ($this->tenants() as $tenant) {
            if (GetTenantMeters::run($tenant)->isEmpty()) {
                continue;
            }

            if ($this->option('sync') === true) {
                $sent += ReportTenantUsage::run($tenant);
            } else {
                ReportTenantUsage::dispatch($tenant);
            }

            $queued++;
        }

        $this->components->info($this->option('sync') === true
            ? "Sent {$sent} meter event(s) for {$queued} tenant(s)."
            : "Queued usage reports for {$queued} tenant(s).");

        return self::SUCCESS;
    }

    /**
     * Whether a plan meters anything lives in JSON metadata, so the filter is
     * in PHP rather than in the query.
     *
     * @return LazyCollection<int, Tenant>
     */
    private function tenants(): LazyCollection
    {
        /** @var list<string> $keys */
        $keys = array_values(array_filter((array) $this->option('tenant'), is_string(...)));

        /** @var LazyCollection<int, Tenant> $tenants */
        $tenants = Numerosis::model(Tenant::class)::query()
            ->whereNotNull('stripe_id')
            ->when($keys !== [], fn ($query) => $query->whereIn('id', $keys))
            ->lazyById();

        return $tenants;
    }
}
