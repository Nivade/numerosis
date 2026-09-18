<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Nvade\Numerosis\Actions\Tenancy\BackupTenant;
use Nvade\Numerosis\Exceptions\Tenancy\TenantBackupFailed;
use Nvade\Numerosis\Models\Central\Tenant;
use Nvade\Numerosis\Numerosis;

#[Description('Snapshot one tenant database, or every provisioned one, to the backup disk')]
#[Signature('tenancy:backup
                            {tenant? : The tenant id}
                            {--all : Back up every provisioned tenant}
                            {--disk= : Filesystem disk to write to, defaulting to numerosis.tenancy.backup.disk}
                            {--chunk= : Restore batch size the artefact is written for, defaulting to 500}')]
class BackupTenantCommand extends Command
{
    public function handle(): int
    {
        $disk = $this->option('disk');
        $disk = is_string($disk) && $disk !== '' ? $disk : null;

        $chunk = $this->option('chunk');
        $chunk = is_numeric($chunk) ? (int) $chunk : 500;

        $failures = 0;

        foreach ($this->tenants() as $tenant) {
            try {
                $path = BackupTenant::run($tenant, $disk, $chunk);

                $this->components->info("Backed up [{$tenant->id}] to {$path}.");
            } catch (TenantBackupFailed $failure) {
                $failures++;

                $this->components->error("Could not back up [{$tenant->id}]: {$failure->getMessage()}");
            }
        }

        return $failures === 0 ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @return list<Tenant>
     */
    private function tenants(): array
    {
        if ($this->option('all')) {
            /** @var list<Tenant> $all */
            $all = Numerosis::model(Tenant::class)::query()->whereNotNull('provisioned_at')->get()->all();

            return $all;
        }

        $id = (string) $this->argument('tenant');
        $tenant = Numerosis::model(Tenant::class)::find($id);

        if (! $tenant instanceof Tenant) {
            $this->components->error("No tenant [{$id}].");

            return [];
        }

        return [$tenant];
    }
}
