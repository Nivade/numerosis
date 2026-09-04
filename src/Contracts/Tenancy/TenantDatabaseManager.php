<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Contracts\Tenancy;

use Nvade\Numerosis\Models\Central\Tenant;

/**
 * Decides whether a tenant's database still needs creating, and which jobs
 * create it.
 *
 * Point `numerosis.tenancy.implementations` at your own class to provision onto
 * separate database servers or regions, or to use a database driver of your
 * own, without reaching into stancl/tenancy internals.
 */
interface TenantDatabaseManager
{
    public function databaseExists(Tenant $tenant): bool;

    /**
     * The jobs that create and prepare a tenant database, run only when it
     * does not exist yet.
     *
     * @return list<class-string>
     */
    public function creationJobs(): array;
}
