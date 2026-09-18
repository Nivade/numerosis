<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Config;
use Nvade\Numerosis\Actions\Billing\Usage\ReconcileTenantUsage;
use Nvade\Numerosis\Models\Central\Tenant;
use Nvade\Numerosis\Numerosis;

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
    public function handle(): int
    {
        $tolerance = $this->tolerance();
        $diverged = 0;

        foreach ($this->tenants() as $tenant) {
            $result = ReconcileTenantUsage::run($tenant, $tolerance);

            foreach ($result['warnings'] as $warning) {
                $this->components->warn($warning);
            }

            $diverged += $result['diverged'];
        }

        if ($diverged > 0) {
            $this->components->warn("{$diverged} meter(s) diverged from Stripe beyond the tolerance.");

            return self::FAILURE;
        }

        $this->components->info('Every metered tenant agrees with Stripe.');

        return self::SUCCESS;
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
