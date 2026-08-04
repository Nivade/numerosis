<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Listeners\Tenancy;

use Illuminate\Bus\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Stancl\Tenancy\Events\SyncedResourceSaved;
use Stancl\Tenancy\Listeners\UpdateSyncedResource as BaseListener;

class UpdateSyncedResource extends BaseListener
{
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 20;

    public int $backoff = 20;

    public function handle(SyncedResourceSaved $event): void
    {
        // Runs as a queued job with retries/backoff above, so a failure here
        // (e.g. the tenant DB/migrations aren't ready yet) is handled by
        // letting the job retry rather than catching anything locally.
        parent::handle($event);
    }
}
