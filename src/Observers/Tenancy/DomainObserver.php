<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Observers\Tenancy;

use Nvade\Numerosis\Models\Central\Domain;
use Nvade\Numerosis\Observers\Concerns\ForgetsCacheKey;
use Nvade\Numerosis\Support\Cache\CacheKeys;

/**
 * Keeps {@see \Nvade\Numerosis\Models\Central\Tenant::primaryDomain()}'s cached entry
 * from outliving the row it describes. Reads `tenant_id` directly rather
 * than the `tenant` relation so a `deleted` hook doesn't trigger a lazy
 * load mid-delete.
 */
class DomainObserver
{
    use ForgetsCacheKey;

    public function saved(Domain $domain): void
    {
        $this->forgetCache(CacheKeys::tenantPrimaryDomain($domain->tenant_id));
    }

    public function deleted(Domain $domain): void
    {
        $this->forgetCache(CacheKeys::tenantPrimaryDomain($domain->tenant_id));
    }
}
