<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Support\Facades\Config;

/**
 * Deletes database session rows past `session.lifetime`. The package prefers
 * the database session driver, and nothing else prunes its table.
 */
#[Description('Delete database session rows past session.lifetime')]
#[Signature('numerosis:prune-sessions')]
class PruneSessions extends Command
{
    public function __construct(private readonly ConnectionResolverInterface $connections)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        if (Config::get('session.driver') !== 'database') {
            $this->components->info('Session driver is not database, nothing to prune.');

            return self::SUCCESS;
        }

        $since = now()->subMinutes(Config::integer('session.lifetime'))->getTimestamp();
        $connection = Config::get('session.connection');

        $deleted = $this->connections
            ->connection(is_string($connection) ? $connection : null)
            ->table(Config::string('session.table', 'sessions'))
            ->where('last_activity', '<', $since)
            ->delete();

        $this->components->info("Deleted {$deleted} expired session row(s).");

        return self::SUCCESS;
    }
}
