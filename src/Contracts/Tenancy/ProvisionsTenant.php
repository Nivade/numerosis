<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Contracts\Tenancy;

use Nvade\Numerosis\Data\Tenancy\TenantProvisionData;
use Nvade\Numerosis\Exceptions\Tenancy\ProvisioningAlreadyClaimed;

/**
 * Entry point for provisioning a tenant. Point
 * `numerosis.tenancy.implementations` at your own class to provision onto
 * separate database servers or regions. Always queue through this contract:
 * dispatching the provisioning action directly loses the guard that keeps
 * concurrent requests for one domain from provisioning twice.
 */
interface ProvisionsTenant
{
    public function queue(TenantProvisionData $data): void;

    /**
     * The same steps, run inline, with a failure thrown at the call site
     * instead of left in `failed_jobs`. For a console command, a seeder or
     * tinker; never a web request, which is what `queue()` is for.
     *
     * @throws ProvisioningAlreadyClaimed When another chain already holds the slug.
     */
    public function now(TenantProvisionData $data): void;
}
