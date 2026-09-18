<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Nvade\Numerosis\Actions\Tenancy\MigrateTenant;
use Nvade\Numerosis\Actions\Tenancy\RecordTenantMigrationLeg;
use Nvade\Numerosis\Enums\Tenancy\MigrationRunStatus;
use Nvade\Numerosis\Models\Central\Tenant;
use Nvade\Numerosis\Numerosis;
use RuntimeException;
use Throwable;

/**
 * One tenant's leg of a fleet migration run.
 *
 * `$tries = 1`: a migration that threw halfway has already changed the schema,
 * and running it again blindly is worse than a failure somebody reads.
 */
final class RunTenantMigration implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(
        public readonly string $runId,
        public readonly string $tenantId,
    ) {}

    public function handle(): void
    {
        $tenant = Numerosis::model(Tenant::class)::find($this->tenantId);

        if (! $tenant instanceof Tenant) {
            throw new RuntimeException("No tenant [{$this->tenantId}] to migrate.");
        }

        RecordTenantMigrationLeg::run($this->runId, $this->tenantId, MigrationRunStatus::Running);

        try {
            $applied = MigrateTenant::run($tenant);
        } catch (Throwable $e) {
            RecordTenantMigrationLeg::run($this->runId, $this->tenantId, MigrationRunStatus::Failed, [], $e->getMessage());

            throw $e;
        }

        RecordTenantMigrationLeg::run($this->runId, $this->tenantId, MigrationRunStatus::Succeeded, $applied);
    }

    /** The queue row is where a worker-side failure is visible; `failed_jobs` is not read by anything here. */
    public function failed(?Throwable $e): void
    {
        RecordTenantMigrationLeg::run($this->runId, $this->tenantId, MigrationRunStatus::Failed, [], $e?->getMessage() ?? 'Migration failed.');
    }
}
