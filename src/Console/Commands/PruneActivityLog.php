<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Config;
use Nvade\Numerosis\Actions\Queries\ReadActivityLog;

/**
 * Spatie's own `activitylog:clean` deletes through the model's default
 * connection, which is the tenant's inside tenancy and the host's default
 * outside it — never reliably the central one. This names the connection.
 */
#[Description('Delete central activity log entries older than the retention window')]
#[Signature('numerosis:prune-activity-log
                            {--days= : Retention window, defaulting to activitylog.clean_after_days}')]
class PruneActivityLog extends Command
{
    public function handle(): void
    {
        $days = (int) ($this->option('days') ?? Config::integer('activitylog.clean_after_days', 365));

        if ($days < 1) {
            $this->components->error('The days option must be a positive integer.');

            return;
        }

        /** @var int $deleted Eloquent's delete() always returns the affected row count. */
        $deleted = ReadActivityLog::run()->where('created_at', '<', now()->subDays($days))->delete();

        $this->components->info("Deleted {$deleted} central activity log entries older than {$days} days.");
    }
}
