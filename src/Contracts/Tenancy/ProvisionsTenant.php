<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Contracts\Tenancy;

use Nvade\Numerosis\Data\Tenancy\TenantProvisionData;

/**
 * Entry point for provisioning a tenant. Point
 * `numerosis.tenancy.implementations` at your own class to provision onto separate
 * database servers or regions.
 *
 * Always queue through this contract rather than dispatching the provisioning
 * action directly — it is what keeps concurrent requests for the same domain
 * from provisioning twice.
 */
interface ProvisionsTenant
{
    public function queue(TenantProvisionData $data): void;
}
