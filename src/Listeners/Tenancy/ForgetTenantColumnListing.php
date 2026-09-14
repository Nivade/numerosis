<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Listeners\Tenancy;

use Illuminate\Database\Events\MigrationsEnded;
use Nvade\Numerosis\Cache\CacheKeys;
use Nvade\Numerosis\Cache\GlobalCache;
use Nvade\Numerosis\Models\Central\Tenant;
use Nvade\Numerosis\Numerosis;

/** A migration is the only thing that changes the `tenants` schema. */
class ForgetTenantColumnListing
{
    public function handle(MigrationsEnded $event): void
    {
        GlobalCache::store()->forget(CacheKeys::tenantCustomColumns());

        Numerosis::model(Tenant::class)::flushColumnCache();
    }
}
