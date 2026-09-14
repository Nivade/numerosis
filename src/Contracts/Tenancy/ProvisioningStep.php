<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Contracts\Tenancy;

use Nvade\Numerosis\Models\Central\TenantProvision;

/**
 * One entry in `numerosis.tenancy.provisioning.steps`, taking the provision row
 * rather than a payload so it reads whatever the step before it wrote.
 *
 * Declare {@see RequiresContributions} to be skipped when the data a step needs
 * was never contributed.
 */
interface ProvisioningStep
{
    public function handle(TenantProvision $provision): void;
}
