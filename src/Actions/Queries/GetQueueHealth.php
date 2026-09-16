<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Actions\Queries;

use Illuminate\Queue\Failed\CountableFailedJobProvider;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Lorisleiva\Actions\Concerns\AsAction;
use Nvade\Numerosis\Data\Observability\QueueHealth;
use Throwable;

/**
 * Queue drivers answer these questions differently and some not at all, so
 * every probe degrades to null instead of throwing: a health document that
 * dies on its own measurement reports the wrong outage.
 */
class GetQueueHealth
{
    use AsAction;

    public function handle(string $queue = 'provisioning'): QueueHealth
    {
        return new QueueHealth(
            $this->depth($queue),
            $this->oldestJobSeconds($queue),
            $this->failedJobs(),
        );
    }

    private function depth(string $queue): ?int
    {
        try {
            return Queue::connection()->size($queue);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Only the database driver stores an enqueue time. Its `jobs` table lives
     * on whatever connection the queue names, never the current one, which on
     * a tenant request is the tenant's.
     */
    private function oldestJobSeconds(string $queue): ?int
    {
        $connection = Config::string('queue.default', 'sync');

        if (Config::get("queue.connections.{$connection}.driver") !== 'database') {
            return null;
        }

        $database = Config::get("queue.connections.{$connection}.connection");

        try {
            $oldest = DB::connection(is_string($database) ? $database : null)
                ->table(Config::string("queue.connections.{$connection}.table", 'jobs'))
                ->where('queue', $queue)
                ->min('created_at');
        } catch (Throwable) {
            return null;
        }

        return is_numeric($oldest) ? max(0, now()->getTimestamp() - (int) $oldest) : null;
    }

    private function failedJobs(): ?int
    {
        try {
            $failer = resolve('queue.failer');

            return $failer instanceof CountableFailedJobProvider ? $failer->count() : null;
        } catch (Throwable) {
            return null;
        }
    }
}
