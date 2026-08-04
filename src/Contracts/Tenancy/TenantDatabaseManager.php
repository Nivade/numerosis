<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Contracts\Tenancy;

use Nvade\Numerosis\Models\Central\Tenant;

/**
 * Decouples `ProvisionTenant`'s decision "does this tenant's database still
 * need creating" from stancl's own job-list coupling
 * (`TenancyServiceProvider::$tenantCreatedJobs`, read directly by
 * `ProvisionTenant::databaseJobs()` today) and from `DatabaseManager`'s
 * concrete `databaseExists()` check. A consumer swapping stancl's database
 * driver, or provisioning onto a separate DB server/region
 * (`.claude/rules/tenant-provisioning.md`), implements this instead of
 * reaching back into stancl internals.
 */
interface TenantDatabaseManager
{
    public function databaseExists(Tenant $tenant): bool;

    /**
     * The jobs `ProvisionTenant`'s chain runs when the database does not yet
     * exist — stancl's own `TenantCreated` `JobPipeline` list by default.
     *
     * @return list<class-string>
     */
    public function creationJobs(): array;
}
