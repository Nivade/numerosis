<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;

#[Description('Delete tenant backup artefacts older than the retention window')]
#[Signature('numerosis:prune-tenant-backups
                            {--days= : Retention window, defaulting to numerosis.tenancy.backup.keep_days}
                            {--disk= : Filesystem disk to prune, defaulting to numerosis.tenancy.backup.disk}
                            {--dry-run : Report what would be deleted without deleting it}')]
class PruneTenantBackups extends Command
{
    public function handle(): int
    {
        $days = (int) ($this->option('days') ?? Config::integer('numerosis.tenancy.backup.keep_days', 30));

        if ($days < 1) {
            $this->components->error('The days option must be a positive integer.');

            return self::FAILURE;
        }

        $diskName = $this->option('disk');
        $disk = Storage::disk(is_string($diskName) && $diskName !== '' ? $diskName : Config::string('numerosis.tenancy.backup.disk', 'local'));

        $cutoff = now()->subDays($days)->getTimestamp();
        $root = trim(Config::string('numerosis.tenancy.backup.path', 'tenant-backups'), '/');

        $deleted = 0;

        foreach ($disk->allFiles($root) as $file) {
            if ($disk->lastModified($file) >= $cutoff) {
                continue;
            }

            if ($this->option('dry-run')) {
                $this->line("  would delete {$file}");

                continue;
            }

            $disk->delete($file);
            $deleted++;
        }

        $this->components->info("Deleted {$deleted} artefact(s) older than {$days} days.");

        return self::SUCCESS;
    }
}
