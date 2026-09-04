<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Nvade\Numerosis\Models\Central\Tenant;
use Nvade\Numerosis\Support\Numerosis;

#[Description('Drop tenant databases that have no matching tenant record, and tenants suspended and never paid for too long')]
#[Signature('tenancy:prune-orphaned-databases
                            {--days=30 : How long a tenant must have been suspended before it is eligible for deletion}
                            {--dry-run : List what would be dropped without dropping anything}
                            {--force : Skip the confirmation prompt}')]
class PruneOrphanedTenantDatabases extends Command
{
    public function handle(): int
    {
        $orphanResult = $this->pruneOrphanedDatabases();
        $suspendedResult = $this->pruneSuspendedTenants();

        return $orphanResult === self::FAILURE || $suspendedResult === self::FAILURE
            ? self::FAILURE
            : self::SUCCESS;
    }

    private function pruneOrphanedDatabases(): int
    {
        $prefix = Config::string('tenancy.database.prefix', 'tenant');

        $tenantClass = Numerosis::model(Tenant::class);

        /** @var Collection<int, string> $tenantIds */
        $tenantIds = $tenantClass::query()->pluck('id');

        $expected = $tenantIds->map(fn (string $id): string => $prefix.$id)->all();

        // Non-string schema names are dropped rather than coerced: this command
        // issues DROP DATABASE, so anything unrecognised must fall out of the
        // orphan list, never into it.
        $orphans = collect(DB::select(
            'SELECT SCHEMA_NAME AS name FROM INFORMATION_SCHEMA.SCHEMATA WHERE SCHEMA_NAME LIKE ?',
            [$prefix.'%'],
        ))
            ->pluck('name')
            ->filter(fn ($name): bool => is_string($name) && $name !== '')
            ->map(fn ($name): string => (string) $name)
            ->reject(fn (string $name): bool => in_array($name, $expected, true))
            ->values();

        if ($orphans->isEmpty()) {
            $this->info('No orphaned tenant databases found.');

            return self::SUCCESS;
        }

        $this->warn("Found {$orphans->count()} orphaned tenant database(s).");

        if ($this->option('dry-run')) {
            $orphans->each(fn (string $name) => $this->line("  would drop {$name}"));

            return self::SUCCESS;
        }

        if (! $this->option('force') && ! $this->confirm("Drop {$orphans->count()} database(s)?")) {
            return self::FAILURE;
        }

        $dropped = 0;

        foreach ($orphans as $name) {
            // Names are quoted rather than interpolated bare: tenant ids have
            // historically contained spaces, commas and apostrophes.
            $escaped = str_replace('`', '``', $name);

            DB::statement("DROP DATABASE IF EXISTS `{$escaped}`");
            $dropped++;
        }

        $this->info("Dropped {$dropped} orphaned tenant database(s).");

        return self::SUCCESS;
    }

    /**
     * Eligibility is only "still suspended after the cutoff", because
     * `RestoreTenant` clears `suspended_at` the moment a subscription
     * recovers, so a tenant suspended this long never paid by construction.
     * `Tenant::delete()` drops the database through the same
     * `TenantDeleted` -> `DeleteDatabase` listener as any other deletion.
     */
    private function pruneSuspendedTenants(): int
    {
        $days = (int) $this->option('days');
        $cutoff = now()->subDays($days);

        $tenantClass = Numerosis::model(Tenant::class);

        $suspended = $tenantClass::query()
            ->whereNotNull('suspended_at')
            ->where('suspended_at', '<', $cutoff);

        $count = $suspended->count();

        if ($count === 0) {
            $this->info('No tenants suspended long enough to prune.');

            return self::SUCCESS;
        }

        $this->warn("Found {$count} tenant(s) suspended for more than {$days} day(s).");

        /** @var Collection<int, Tenant> $tenants */
        $tenants = $suspended->get();

        if ($this->option('dry-run')) {
            $tenants->each(fn (Tenant $tenant) => $this->line("  would delete {$tenant->id} (suspended {$tenant->suspended_at})"));

            return self::SUCCESS;
        }

        if (! $this->option('force') && ! $this->confirm("Permanently delete {$count} suspended tenant(s) and their data?")) {
            return self::FAILURE;
        }

        $deleted = 0;

        foreach ($tenants as $tenant) {
            $tenant->delete();
            $deleted++;
        }

        $this->info("Deleted {$deleted} suspended tenant(s).");

        return self::SUCCESS;
    }
}
