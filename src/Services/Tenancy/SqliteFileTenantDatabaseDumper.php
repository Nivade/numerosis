<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Services\Tenancy;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Nvade\Numerosis\Contracts\Tenancy\TenantDatabaseDumper;
use Nvade\Numerosis\Exceptions\Tenancy\TenantBackupFailed;
use Nvade\Numerosis\Models\Central\Tenant;
use Nvade\Numerosis\Numerosis;
use Stancl\Tenancy\Contracts\TenantWithDatabase;

/**
 * `VACUUM INTO`, not a file copy: copying a SQLite file while another
 * connection is mid-write produces an artefact that looks fine and opens
 * corrupt, and the write-ahead log is a second file a copy would miss.
 */
class SqliteFileTenantDatabaseDumper implements TenantDatabaseDumper
{
    public function isAvailable(): bool
    {
        return true;
    }

    public function unavailableReason(): ?string
    {
        return null;
    }

    public function carriesSchema(): bool
    {
        return true;
    }

    public function dump(TenantWithDatabase $tenant, string $file): void
    {
        @unlink($file);

        $this->model($tenant)->runHere(function () use ($file): void {
            DB::connection()->statement('vacuum into ?', [$file]);
        });
    }

    public function restore(TenantWithDatabase $tenant, string $file): void
    {
        if (! is_file($file)) {
            throw TenantBackupFailed::artefactMissing($file);
        }

        $target = $this->path($tenant);

        DB::purge('tenant');

        foreach ([$target, $target.'-wal', $target.'-shm'] as $stale) {
            @unlink($stale);
        }

        if (! copy($file, $target)) {
            throw TenantBackupFailed::unwritable($target);
        }
    }

    private function path(TenantWithDatabase $tenant): string
    {
        $name = (string) $tenant->database()->getName();

        return str_contains($name, DIRECTORY_SEPARATOR)
            ? $name
            : rtrim(Config::string('database.connections.tenant.database_path', database_path()), DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.$name;
    }

    /**
     * The package's own model, which carries `runHere()`. stancl's own
     * `run()` leaves tenancy initialized when its callback throws.
     */
    private function model(TenantWithDatabase $tenant): Tenant
    {
        if ($tenant instanceof Tenant) {
            return $tenant;
        }

        $model = Numerosis::model(Tenant::class)::query()->findOrFail($tenant->getTenantKey());

        return $model instanceof Tenant ? $model : throw TenantBackupFailed::artefactMissing((string) $tenant->getTenantKey());
    }
}
