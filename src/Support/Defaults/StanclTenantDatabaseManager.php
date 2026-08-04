<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Support\Defaults;

use Nvade\Numerosis\Contracts\Tenancy\TenantDatabaseManager;
use Nvade\Numerosis\Models\Central\Tenant;
use Nvade\Numerosis\Providers\TenancyServiceProvider;

class StanclTenantDatabaseManager implements TenantDatabaseManager
{
    public function databaseExists(Tenant $tenant): bool
    {
        $database = $tenant->database()->getName();

        return $database !== null && $database !== '' && $tenant->database()->manager()->databaseExists($database);
    }

    /**
     * @return list<class-string>
     */
    public function creationJobs(): array
    {
        return TenancyServiceProvider::$tenantCreatedJobs;
    }
}
