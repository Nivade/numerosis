<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Listeners\Tenancy;

use Illuminate\Bus\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Stancl\Tenancy\Listeners\UpdateSyncedResource as BaseListener;

/**
 * Adds retry/backoff and makes the listener queueable — the actual sync
 * logic stays entirely in the parent's `handle()`, inherited as-is rather
 * than overridden. `handle()` runs as a queued job (tries/backoff below),
 * so a failure (e.g. the tenant DB/migrations aren't ready yet) is handled
 * by the job retrying.
 */
class UpdateSyncedResource extends BaseListener
{
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 20;

    public int $backoff = 20;
}
