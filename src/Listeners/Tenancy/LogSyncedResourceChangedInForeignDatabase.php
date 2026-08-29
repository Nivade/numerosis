<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Listeners\Tenancy;

use Illuminate\Support\Facades\Log;

/**
 * Renamed, not just moved: v3's `Stancl\Tenancy\Events\SyncedResourceChangedInForeignDatabase`
 * is v4's `Stancl\Tenancy\ResourceSyncing\Events\SyncedResourceSavedInForeignDatabase`.
 * `TenancyVersion::syncedResourceChangedInForeignDatabaseEventClass()`
 * chooses the right one for `Providers\TenancyServiceProvider`'s event map,
 * but this listener does not extend anything, so there is no LSP
 * requirement to name either real class here — the native parameter type
 * stays `object`, narrowed to the real union only via `@param`, so this one
 * class works unmodified against whichever version dispatches the event.
 */
class LogSyncedResourceChangedInForeignDatabase
{
    /** @param \Stancl\Tenancy\Events\SyncedResourceChangedInForeignDatabase|\Stancl\Tenancy\ResourceSyncing\Events\SyncedResourceSavedInForeignDatabase $event */
    public function handle(object $event): void
    {
        Log::warning('Synced resource changed in foreign database', [
            'central_model' => $event->model->getCentralModelName(),
            'global_id' => $event->model->getGlobalIdentifierKey(),
            'tenant_id' => $event->tenant?->getTenantKey(),
        ]);
    }
}
