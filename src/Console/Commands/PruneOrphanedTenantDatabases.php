<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Config;
use Nvade\Numerosis\Actions\Tenancy\BackupTenant;
use Nvade\Numerosis\Contracts\Tenancy\TenantDatabaseManager;
use Nvade\Numerosis\Models\Central\Tenant;
use Nvade\Numerosis\Numerosis;
use Throwable;

#[Description('Drop tenant databases that have no matching tenant record, and tenants suspended or closed for too long')]
#[Signature('tenancy:prune-orphaned-databases
                            {--days= : How many days a tenant must have been suspended or closed before it is eligible for deletion, defaulting to numerosis.tenancy.closure.grace_days}
                            {--dry-run : List what would be dropped without dropping anything}
                            {--force : Skip the confirmation prompt}')]
class PruneOrphanedTenantDatabases extends Command
{
    public function __construct(private readonly TenantDatabaseManager $databases)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $results = [
            $this->pruneOrphanedDatabases(),
            $this->pruneSuspendedTenants(),
            $this->pruneClosedTenants(),
        ];

        return in_array(self::FAILURE, $results, true) ? self::FAILURE : self::SUCCESS;
    }

    /** One number for both cohorts: the recovery window a closed tenant is promised. */
    private function graceDays(): int
    {
        $option = $this->option('days');

        return is_numeric($option)
            ? (int) $option
            : Config::integer('numerosis.tenancy.closure.grace_days', 30);
    }

    private function pruneOrphanedDatabases(): int
    {
        $prefix = Config::string('tenancy.database.prefix', 'tenant');

        $tenantClass = Numerosis::model(Tenant::class);

        /** @var Collection<int, string> $tenantIds */
        $tenantIds = $tenantClass::query()->pluck('id');

        $expected = $tenantIds->map(fn (string $id): string => $prefix.$id)->all();

        $orphans = collect($this->databases->namesMatchingPrefix($prefix))
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
            $this->databases->dropDatabase($name);
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
        $days = $this->graceDays();
        $cutoff = now()->subDays($days);

        $tenantClass = Numerosis::model(Tenant::class);

        // A closed tenant is only ever deleted through the closed cohort,
        // which `purge_closed` gates; reaching it here would bypass that.
        $suspended = $tenantClass::query()
            ->whereNull('closed_at')
            ->whereNotNull('suspended_at')
            ->where('suspended_at', '<', $cutoff);

        /** @var Collection<int, Tenant> $tenants */
        $tenants = $suspended->get();
        $count = $tenants->count();

        if ($count === 0) {
            $this->info('No tenants suspended long enough to prune.');

            return self::SUCCESS;
        }

        $this->warn("Found {$count} tenant(s) suspended for more than {$days} day(s).");

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

    /**
     * Off unless `numerosis.tenancy.closure.purge_closed` says otherwise:
     * this is the only path that destroys a tenant its owner was promised a
     * recovery window for, and there is no backup to restore it from yet.
     */
    private function pruneClosedTenants(): int
    {
        if (! Config::boolean('numerosis.tenancy.closure.purge_closed', false)) {
            $this->info('Purging closed tenants is off (numerosis.tenancy.closure.purge_closed).');

            return self::SUCCESS;
        }

        $days = $this->graceDays();
        $cutoff = now()->subDays($days);

        $tenantClass = Numerosis::model(Tenant::class);

        $closed = $tenantClass::query()
            ->whereNotNull('closed_at')
            ->where('closed_at', '<', $cutoff);

        /** @var Collection<int, Tenant> $tenants */
        $tenants = $closed->get();
        $count = $tenants->count();

        if ($count === 0) {
            $this->info('No tenants closed long enough to purge.');

            return self::SUCCESS;
        }

        $this->warn("Found {$count} tenant(s) closed for more than {$days} day(s).");

        if ($this->option('dry-run')) {
            $tenants->each(fn (Tenant $tenant) => $this->line("  would delete {$tenant->id} (closed {$tenant->closed_at})"));

            return self::SUCCESS;
        }

        if (! $this->option('force') && ! $this->confirm("Permanently delete {$count} closed tenant(s) and their data?")) {
            return self::FAILURE;
        }

        $deleted = 0;
        $skipped = 0;

        foreach ($tenants as $tenant) {
            if (! $this->backedUp($tenant)) {
                $skipped++;

                continue;
            }

            $tenant->delete();
            $deleted++;
        }

        $this->info("Deleted {$deleted} closed tenant(s).");

        if ($skipped > 0) {
            $this->warn("Kept {$skipped} tenant(s) whose final backup failed.");

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * The interlock that makes the recovery window mean something: a purge
     * takes one last snapshot, and a tenant whose backup failed keeps its
     * database. Any failure counts, a missing database included: the tenant
     * this cannot snapshot is exactly the one not to drop.
     */
    private function backedUp(Tenant $tenant): bool
    {
        if (! Config::boolean('numerosis.tenancy.backup.before_purge', true)) {
            return true;
        }

        try {
            $path = BackupTenant::run($tenant);
        } catch (Throwable $failure) {
            $this->error("Not deleting {$tenant->id}: its final backup failed ({$failure->getMessage()}).");

            return false;
        }

        $this->line("  backed up {$tenant->id} to {$path}");

        return true;
    }
}
