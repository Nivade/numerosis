<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Data\Observability;

use Illuminate\Support\Facades\Config;
use Spatie\LaravelData\Attributes\MapInputName;
use Spatie\LaravelData\Attributes\MapOutputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

/**
 * What the health endpoint serves and the staff screens read. Booleans, counts
 * and ages; never a tenant name or slug.
 */
#[MapInputName(SnakeCaseMapper::class)]
#[MapOutputName(SnakeCaseMapper::class)]
final class HealthReport extends Data
{
    public function __construct(
        public bool $centralDatabase,
        public QueueHealth $queue,
        public ProvisioningHealth $provisioning,
        public ?int $schedulerLastRunSeconds,
    ) {}

    /**
     * The central database and the scheduler decide this. A deep provisioning
     * queue is what an operator reads the counts for, and a monitor paging on
     * it would page on every busy morning.
     */
    public function healthy(): bool
    {
        return $this->centralDatabase && ! $this->schedulerStalled();
    }

    /**
     * No heartbeat ever written is unknown, not failed -- a deployment
     * running no scheduler at all must not report unhealthy on that basis
     * alone.
     */
    private function schedulerStalled(): bool
    {
        if ($this->schedulerLastRunSeconds === null) {
            return false;
        }

        return $this->schedulerLastRunSeconds > Config::integer('numerosis.health.scheduler_stale_after_seconds', 300);
    }
}
