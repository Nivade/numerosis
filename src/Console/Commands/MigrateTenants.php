<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use Nvade\Numerosis\Actions\Queries\GetPendingTenantMigrations;
use Nvade\Numerosis\Actions\Tenancy\MigrateTenant;
use Nvade\Numerosis\Actions\Tenancy\RecordTenantMigrationLeg;
use Nvade\Numerosis\Enums\Tenancy\MigrationRunStatus;
use Nvade\Numerosis\Jobs\RunTenantMigration;
use Nvade\Numerosis\Models\Central\Tenant;
use Nvade\Numerosis\Models\Central\TenantMigrationRun;
use Nvade\Numerosis\Numerosis;
use Throwable;

#[Description('Apply outstanding tenant migrations across the fleet, one tenant at a time, recording every leg')]
#[Signature('tenancy:migrate
                            {--tenants=* : Tenant ids to migrate, defaulting to every tenant}
                            {--pending : Skip tenants that are already up to date}
                            {--dry-run : Report what each tenant would run, changing nothing}
                            {--chunk= : Tenants per chunk, defaulting to numerosis.tenancy.migrations.chunk}
                            {--delay= : Seconds to pause between chunks, defaulting to numerosis.tenancy.migrations.delay}
                            {--queue : Dispatch one job per tenant instead of migrating inline}
                            {--stop-on-failure : Abandon the run at the first failing tenant}
                            {--resume= : Continue the run with this id, skipping tenants it already finished}')]
class MigrateTenants extends Command
{
    private string $runId;

    /** @var array<string, string> */
    private array $failures = [];

    private int $migrated = 0;

    private int $skipped = 0;

    public function handle(): int
    {
        // Console commands are resolved once and reused, so a second
        // invocation in the same process inherits the first one's tally and
        // reports failures that belong to a run that already ended.
        $this->failures = [];
        $this->migrated = 0;
        $this->skipped = 0;

        $this->runId = $this->resolveRunId();

        if ($this->option('dry-run')) {
            return $this->reportPending();
        }

        $total = $this->tenants()->count();

        if ($total === 0) {
            $this->info('No tenants to migrate.');

            return self::SUCCESS;
        }

        $this->line("Run {$this->runId}: {$total} tenant(s).");

        if ($this->option('queue')) {
            return $this->dispatchLegs();
        }

        return $this->migrateInline($total);
    }

    /**
     * A resumed run keeps its id, which is what makes its finished legs
     * skippable and the screen show one run rather than two.
     */
    private function resolveRunId(): string
    {
        $resume = $this->option('resume');

        return is_string($resume) && $resume !== '' ? $resume : (string) Str::ulid();
    }

    /**
     * @return Builder<Tenant>
     */
    private function tenants(): Builder
    {
        /** @var list<string> $ids */
        $ids = (array) $this->option('tenants');

        return Numerosis::model(Tenant::class)::query()
            ->when($ids !== [], fn (Builder $query) => $query->whereIn('id', $ids))
            ->orderBy('id');
    }

    private function reportPending(): int
    {
        $rows = [];

        $this->eachChunk(function (Tenant $tenant) use (&$rows): void {
            $pending = GetPendingTenantMigrations::run($tenant);

            if ($pending === [] && $this->option('pending')) {
                return;
            }

            $rows[] = [$tenant->id, count($pending), implode(', ', $pending) ?: '—'];
        });

        if ($rows === []) {
            $this->info('Every tenant is up to date.');

            return self::SUCCESS;
        }

        $this->table(['Tenant', 'Pending', 'Migrations'], $rows);
        $this->comment('Dry run: nothing was applied.');

        return self::SUCCESS;
    }

    private function migrateInline(int $total): int
    {
        $this->output->progressStart($total);

        $stopped = false;

        $this->eachChunk(function (Tenant $tenant) use (&$stopped): bool {
            if ($stopped) {
                return false;
            }

            $tenantId = $tenant->id;

            if ($this->alreadyFinished($tenantId) || $this->isUpToDate($tenant)) {
                $this->skipped++;
                $this->output->progressAdvance();

                return true;
            }

            RecordTenantMigrationLeg::started($this->runId, $tenantId);

            try {
                $applied = MigrateTenant::run($tenant);

                RecordTenantMigrationLeg::succeeded($this->runId, $tenantId, $applied);

                $this->migrated++;
            } catch (Throwable $e) {
                RecordTenantMigrationLeg::failed($this->runId, $tenantId, $e->getMessage());

                $this->failures[$tenantId] = $e->getMessage();

                $stopped = (bool) $this->option('stop-on-failure');
            }

            $this->output->progressAdvance();

            return ! $stopped;
        });

        $this->output->progressFinish();

        return $this->summarise($stopped);
    }

    private function dispatchLegs(): int
    {
        $queue = Config::string('numerosis.tenancy.migrations.queue', 'migrations');
        $dispatched = 0;

        $this->eachChunk(function (Tenant $tenant) use ($queue, &$dispatched): void {
            $tenantId = $tenant->id;

            if ($this->alreadyFinished($tenantId) || $this->isUpToDate($tenant)) {
                $this->skipped++;

                return;
            }

            RecordTenantMigrationLeg::run($this->runId, $tenantId, MigrationRunStatus::Pending);

            dispatch(new RunTenantMigration($this->runId, $tenantId))->onQueue($queue);

            $dispatched++;
        });

        $this->info("Dispatched {$dispatched} tenant(s) to the [{$queue}] queue, {$this->skipped} skipped.");
        $this->comment("Nothing runs until a worker consumes [{$queue}].");
        $this->comment("Read the result with: tenancy:migrate --resume={$this->runId} --dry-run");

        return self::SUCCESS;
    }

    /**
     * Chunked and paced: thousands of `ALTER` statements saturate a database
     * server, and the pause between chunks is the only knob an operator has.
     *
     * @param  callable(Tenant): (bool|void)  $leg
     */
    private function eachChunk(callable $leg): void
    {
        $size = $this->intOption('chunk', 'numerosis.tenancy.migrations.chunk', 50);
        $delay = $this->intOption('delay', 'numerosis.tenancy.migrations.delay', 0);
        $after = null;

        while (true) {
            /** @var Collection<int, Tenant> $tenants */
            $tenants = $this->tenants()
                ->when($after !== null, fn (Builder $query) => $query->where('id', '>', $after))
                ->limit($size)
                ->get();

            if ($tenants->isEmpty()) {
                return;
            }

            foreach ($tenants as $tenant) {
                if ($leg($tenant) === false) {
                    return;
                }
            }

            $after = $tenants->last()->id;

            if ($delay > 0) {
                Sleep::sleep($delay);
            }
        }
    }

    private function alreadyFinished(string $tenantId): bool
    {
        if (! is_string($this->option('resume')) || $this->option('resume') === '') {
            return false;
        }

        return Numerosis::model(TenantMigrationRun::class)::query()
            ->where('run_id', $this->runId)
            ->where('tenant_id', $tenantId)
            ->whereIn('status', [MigrationRunStatus::Succeeded, MigrationRunStatus::Skipped])
            ->exists();
    }

    private function isUpToDate(Tenant $tenant): bool
    {
        if (! $this->option('pending')) {
            return false;
        }

        if (GetPendingTenantMigrations::run($tenant) !== []) {
            return false;
        }

        RecordTenantMigrationLeg::skipped($this->runId, $tenant->id);

        return true;
    }

    private function summarise(bool $stopped): int
    {
        $this->newLine();
        $this->info("Migrated {$this->migrated} tenant(s), skipped {$this->skipped}.");

        if ($this->failures === []) {
            return self::SUCCESS;
        }

        $this->error(count($this->failures).' tenant(s) failed:');

        foreach ($this->failures as $tenantId => $error) {
            $this->line("  {$tenantId}: {$error}");
        }

        if ($stopped) {
            $this->warn('Stopped at the first failure; the rest of the fleet was not touched.');
        }

        $this->comment("Fix, then: tenancy:migrate --resume={$this->runId}");

        return self::FAILURE;
    }

    private function intOption(string $name, string $configKey, int $default): int
    {
        $option = $this->option($name);

        return is_numeric($option) ? (int) $option : Config::integer($configKey, $default);
    }
}
