<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Services\Tenancy;

use Nvade\Numerosis\Contracts\Tenancy\TenantDatabaseManager;
use Nvade\Numerosis\Models\Central\Tenant;

class StanclTenantDatabaseManager implements TenantDatabaseManager
{
    public function databaseExists(Tenant $tenant): bool
    {
        $database = $tenant->database()->getName();

        return $database !== null && $database !== '' && $tenant->database()->manager()->databaseExists($database);
    }
}
