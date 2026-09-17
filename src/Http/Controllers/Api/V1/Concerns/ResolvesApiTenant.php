<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Http\Controllers\Api\V1\Concerns;

use Nvade\Numerosis\Models\Central\Tenant;

/**
 * Every endpoint reads the tenant from tenancy, never from the request. A token
 * is issued inside one workspace and its identification middleware has already
 * decided which — accepting an id from the caller is how tenant A reads tenant
 * B.
 */
trait ResolvesApiTenant
{
    protected function apiTenant(): Tenant
    {
        $tenant = tenant();

        abort_unless($tenant instanceof Tenant, 404);

        return $tenant;
    }
}
