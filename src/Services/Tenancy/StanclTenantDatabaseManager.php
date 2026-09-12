<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Services\Tenancy;

use Nvade\Numerosis\Contracts\Tenancy\TenantDatabaseManager;
use Stancl\Tenancy\Contracts\TenantWithDatabase;

class StanclTenantDatabaseManager implements TenantDatabaseManager
{
    public function databaseExists(TenantWithDatabase $tenant): bool
    {
        $database = $tenant->database()->getName();

        return $database !== null && $database !== '' && $tenant->database()->manager()->databaseExists($database);
    }
}
