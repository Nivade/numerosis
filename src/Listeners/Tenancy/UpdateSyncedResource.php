<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Listeners\Tenancy;

use Illuminate\Bus\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Nvade\Numerosis\Support\Compat\Tenancy\UpdateOrCreateSyncedResource as BaseListener;

/**
 * Adds retry/backoff and makes the listener queueable — the actual sync
 * logic stays entirely in the parent's `handle()`, inherited as-is rather
 * than overridden. An override used to exist here purely to attach a
 * comment; it is gone because a `handle(SyncedResourceSaved $event)`
 * override cannot be written in a version-safe way: PHPStan resolves
 * `Support\Compat\Tenancy\UpdateOrCreateSyncedResource`'s conditionally
 * declared parent to a single canonical branch regardless of which real
 * version is installed, so naming *either* real event class as the
 * parameter type gets reported as non-contravariant with the branch
 * PHPStan didn't pick. Not overriding the method sidesteps the check
 * instead of fighting it — `handle()` runs as a queued job (tries/backoff
 * above), so a failure (e.g. the tenant DB/migrations aren't ready yet) is
 * handled by the job retrying, same as before.
 */
class UpdateSyncedResource extends BaseListener
{
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 20;

    public int $backoff = 20;
}
