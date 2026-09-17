<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Config;

/**
 * Deletes read in-app notifications past the retention window. Unread ones stay
 * whatever their age: the point of the bell is that nobody has looked yet.
 */
#[Description('Delete read in-app notifications older than the retention window')]
#[Signature('numerosis:prune-notifications
                            {--days= : Retention window, defaulting to numerosis.notifications.keep_days}')]
class PruneNotifications extends Command
{
    public function handle(): int
    {
        $option = $this->option('days');
        $days = is_numeric($option) ? (int) $option : Config::integer('numerosis.notifications.keep_days', 90);

        if ($days < 1) {
            $this->components->error('The days option must be a positive integer.');

            return self::FAILURE;
        }

        // Pinned to the central connection: `notifications` is a central table
        // and this command may run while tenancy has swapped the default one,
        // which would otherwise prune a tenant database's table or none at all.
        $stale = DatabaseNotification::on(Config::string('tenancy.database.central_connection', 'central'))
            ->whereNotNull('read_at')
            ->where('created_at', '<', now()->subDays($days));

        $deleted = $stale->count();

        $stale->delete();

        $this->components->info("Deleted {$deleted} read notification(s) older than {$days} days.");

        return self::SUCCESS;
    }
}
