<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Actions\Tenancy;

use Illuminate\Support\Facades\Config;
use Lorisleiva\Actions\Concerns\AsAction;
use Nvade\Numerosis\Models\Central\Domain;
use Nvade\Numerosis\Models\Central\Tenant;

class CreateTenantDomain
{
    use AsAction;

    public function handle(Tenant $tenant, string $subdomain): Domain
    {
        return $tenant->domains()->firstOrCreate(
            ['id' => $subdomain],
            ['domain' => $subdomain.'.'.Config::string('numerosis.domains.apex')],
        );
    }
}
