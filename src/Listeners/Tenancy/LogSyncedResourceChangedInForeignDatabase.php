<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Listeners\Tenancy;

use Illuminate\Support\Facades\Log;

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
