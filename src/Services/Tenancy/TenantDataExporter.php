<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Services\Tenancy;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Nvade\Numerosis\Contracts\Tenancy\ExportsTenantData;
use Nvade\Numerosis\Exceptions\Tenancy\TenantBackupFailed;
use Nvade\Numerosis\Models\Central\Invitation;
use Nvade\Numerosis\Models\Central\Membership;
use Nvade\Numerosis\Models\Central\Subscription;
use Nvade\Numerosis\Models\Central\Tenant;
use Nvade\Numerosis\Numerosis;
use Stancl\Tenancy\Contracts\TenantWithDatabase;
use ZipArchive;

/**
 * The portable, readable half of the two formats: a zip of JSON Lines per
 * table, the tenant's files, and the central rows that belong to the tenant.
 * A physical dump restores exactly and is unreadable to a customer; this reads
 * and cannot restore a schema.
 *
 * Every table is streamed through a temporary file, so a tenant with a large
 * table costs one row of memory rather than one table.
 */
class TenantDataExporter implements ExportsTenantData
{
    /**
     * @param  string|null  $forGlobalUserId  Narrows every table to one person's rows, which is what a
     *                                        subject access request asks for.
     * @return string The archive's path on the disk.
     */
    public function export(TenantWithDatabase $tenant, ?string $forGlobalUserId = null, ?string $disk = null): string
    {
        $tenant = $this->model($tenant);

        $workingDirectory = $this->temporaryDirectory();
        $archivePath = $workingDirectory.DIRECTORY_SEPARATOR.'export.zip';

        $zip = new ZipArchive;

        if ($zip->open($archivePath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw TenantBackupFailed::unwritable($archivePath);
        }

        try {
            $zip->addFromString('manifest.json', json_encode([
                'tenant' => $tenant->id,
                'name' => $tenant->name,
                'exported_at' => now()->toIso8601String(),
                'scope' => $forGlobalUserId ?? 'tenant',
                'format' => 'json-lines',
            ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));

            $written = $this->writeTenantTables($tenant, $workingDirectory, $forGlobalUserId);
            $written += $this->writeCentralRows($tenant, $workingDirectory, $forGlobalUserId);

            foreach ($written as $entry => $file) {
                $zip->addFile($file, $entry);
            }

            $this->addTenantFiles($zip, $tenant);

            $zip->close();

            return $this->store($tenant, $archivePath, $disk);
        } finally {
            $this->deleteDirectory($workingDirectory);
        }
    }

    /**
     * @return array<string, string>
     */
    private function writeTenantTables(Tenant $tenant, string $directory, ?string $forGlobalUserId): array
    {
        /** @var array<string, string> $files */
        $files = $tenant->runHere(function () use ($directory, $forGlobalUserId): array {
            $connection = DB::connection();
            $written = [];

            foreach ($connection->getSchemaBuilder()->getTables($connection->getDatabaseName()) as $table) {
                $name = (string) ($table['name'] ?? '');

                if ($name === '' || in_array($name, ['migrations', 'jobs', 'failed_jobs', 'cache', 'cache_locks', 'sessions'], true)) {
                    continue;
                }

                $query = $connection->table($name);

                if ($forGlobalUserId !== null && ! $this->narrow($connection->getSchemaBuilder()->getColumnListing($name), $query, $forGlobalUserId)) {
                    continue;
                }

                $written['tables/'.$name.'.jsonl'] = $this->writeRows($directory.DIRECTORY_SEPARATOR.$name.'.jsonl', $query);
            }

            return $written;
        });

        return $files;
    }

    /**
     * @return array<string, string>
     */
    private function writeCentralRows(Tenant $tenant, string $directory, ?string $forGlobalUserId): array
    {
        $tenantId = $tenant->id;

        $queries = [
            'memberships' => Numerosis::model(Membership::class)::query()->getQuery()->where('tenant_id', $tenantId),
            'invitations' => Numerosis::model(Invitation::class)::query()->getQuery()->where('tenant_id', $tenantId),
            'subscriptions' => Numerosis::model(Subscription::class)::query()->getQuery()
                ->where('subscribable_id', $tenantId)
                ->select(['id', 'type', 'stripe_status', 'stripe_price', 'quantity', 'trial_ends_at', 'ends_at', 'created_at']),
        ];

        $written = [];

        foreach ($queries as $name => $query) {
            if ($forGlobalUserId !== null && $name === 'memberships') {
                $query->where('global_user_id', $forGlobalUserId);
            }

            $written['central/'.$name.'.jsonl'] = $this->writeRows($directory.DIRECTORY_SEPARATOR.'central-'.$name.'.jsonl', $query);
        }

        return $written;
    }

    /**
     * Narrows a table to one person's rows. Answers false when the table has
     * no column naming a person, since a subject access request must not hand
     * back rows about everybody else.
     *
     * @param  list<string>  $columns
     */
    private function narrow(array $columns, Builder $query, string $globalUserId): bool
    {
        if (in_array('global_id', $columns, true)) {
            $query->where('global_id', $globalUserId);

            return true;
        }

        if (in_array('global_user_id', $columns, true)) {
            $query->where('global_user_id', $globalUserId);

            return true;
        }

        return false;
    }

    private function writeRows(string $file, Builder $query): string
    {
        $handle = fopen($file, 'wb');

        if ($handle === false) {
            throw TenantBackupFailed::unwritable($file);
        }

        try {
            // `cursor()` rather than `lazy()`: lazy chunking needs an
            // orderBy, and not every table here has an obvious key to take.
            foreach ($query->cursor() as $row) {
                fwrite($handle, json_encode((array) $row, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE)."\n");
            }
        } finally {
            fclose($handle);
        }

        return $file;
    }

    /**
     * The tenant's own files, read through the tenant-suffixed `local` disk
     * rather than a path built by hand.
     */
    private function addTenantFiles(ZipArchive $zip, Tenant $tenant): void
    {
        $tenant->runHere(function () use ($zip): void {
            $disk = Storage::disk('local');

            foreach ($disk->allFiles() as $file) {
                $path = $disk->path($file);

                if (is_file($path)) {
                    $zip->addFile($path, 'files/'.$file);
                }
            }
        });
    }

    private function store(Tenant $tenant, string $archivePath, ?string $disk): string
    {
        $path = sprintf('tenant-exports/%s/%s.zip', $tenant->id, now()->format('Y-m-d-His'));

        $handle = fopen($archivePath, 'rb');

        if ($handle === false) {
            throw TenantBackupFailed::unreadable($archivePath);
        }

        try {
            Storage::disk($disk ?? config()->string('numerosis.tenancy.backup.disk', 'local'))->writeStream($path, $handle);
        } finally {
            fclose($handle);
        }

        return $path;
    }

    private function temporaryDirectory(): string
    {
        $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'numerosis-export-'.bin2hex(random_bytes(6));

        mkdir($directory, 0700, true);

        return $directory;
    }

    private function deleteDirectory(string $directory): void
    {
        foreach ((array) glob($directory.DIRECTORY_SEPARATOR.'*') as $file) {
            if (is_string($file)) {
                @unlink($file);
            }
        }

        @rmdir($directory);
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
