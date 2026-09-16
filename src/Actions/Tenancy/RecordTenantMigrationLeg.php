<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Actions\Tenancy;

use Lorisleiva\Actions\Concerns\AsAction;
use Nvade\Numerosis\Enums\Tenancy\MigrationRunStatus;
use Nvade\Numerosis\Models\Central\TenantMigrationRun;
use Nvade\Numerosis\Numerosis;

/**
 * Writes one tenant's leg of a run, whether the command ran it inline or a
 * worker did. Keyed on `(run_id, tenant_id)`, so a resumed or re-dispatched
 * leg amends its row instead of adding a second one.
 */
class RecordTenantMigrationLeg
{
    use AsAction;

    /**
     * @param  list<string>  $migrations
     */
    public function handle(
        string $runId,
        string $tenantId,
        MigrationRunStatus $status,
        array $migrations = [],
        ?string $error = null,
    ): TenantMigrationRun {
        $attributes = ['status' => $status];

        if ($status === MigrationRunStatus::Running) {
            $attributes['started_at'] = now();
            $attributes['error'] = null;
        }

        if (in_array($status, [MigrationRunStatus::Succeeded, MigrationRunStatus::Failed, MigrationRunStatus::Skipped], true)) {
            $attributes['finished_at'] = now();
        }

        if ($migrations !== []) {
            $attributes['migrations'] = $migrations;
        }

        if ($error !== null) {
            $attributes['error'] = $error;
        }

        /** @var TenantMigrationRun $leg */
        $leg = Numerosis::model(TenantMigrationRun::class)::query()->updateOrCreate(
            ['run_id' => $runId, 'tenant_id' => $tenantId],
            $attributes,
        );

        return $leg;
    }

    public static function started(string $runId, string $tenantId): TenantMigrationRun
    {
        return self::run($runId, $tenantId, MigrationRunStatus::Running);
    }

    /**
     * @param  list<string>  $migrations
     */
    public static function succeeded(string $runId, string $tenantId, array $migrations): TenantMigrationRun
    {
        return self::run($runId, $tenantId, MigrationRunStatus::Succeeded, $migrations);
    }

    public static function failed(string $runId, string $tenantId, string $error): TenantMigrationRun
    {
        return self::run($runId, $tenantId, MigrationRunStatus::Failed, [], $error);
    }

    public static function skipped(string $runId, string $tenantId): TenantMigrationRun
    {
        return self::run($runId, $tenantId, MigrationRunStatus::Skipped);
    }
}
