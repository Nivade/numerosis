<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Listeners\Tenancy;

use Illuminate\Support\Facades\Log;

/**
 * The native parameter type stays `object`, narrowed only via `@param`:
 * dev-master renames this event to
 * `Stancl\Tenancy\ResourceSyncing\Events\SyncedResourceSavedInForeignDatabase`
 * and the shape it reads is identical on both, so not naming the class
 * keeps this listener working across the port
 * (`.claude/rules/stancl-tenancy-v4.md`).
 */
class LogSyncedResourceChangedInForeignDatabase
{
    /** @param \Stancl\Tenancy\Events\SyncedResourceChangedInForeignDatabase $event */
    public function handle(object $event): void
    {
        Log::warning('Synced resource changed in foreign database', [
            'central_model' => $event->model->getCentralModelName(),
            'global_id' => $event->model->getGlobalIdentifierKey(),
            'tenant_id' => $event->tenant?->getTenantKey(),
        ]);
    }
}
