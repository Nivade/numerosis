<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Actions\Tenancy;

use Lorisleiva\Actions\Concerns\AsAction;
use Nvade\Numerosis\Concerns\Tenancy\TagsSentryScopeWithTenant;
use Nvade\Numerosis\Contracts\Tenancy\ProvisioningStep;
use Nvade\Numerosis\Models\Central\TenantProvision;

/**
 * Signals that provisioning finished. Configured last because it is what
 * reports the tenant ready.
 *
 * It no longer carries its own failure handler or twenty retries. Both existed
 * because a silent exhaustion here left the UI spinning forever; the chain has
 * one terminal handler now, so exhaustion marks the provision failed wherever
 * it happens.
 */
class FinalizeTenantProvisioning implements ProvisioningStep
{
    use AsAction;
    use TagsSentryScopeWithTenant;

    public function handle(TenantProvision $provision): void
    {
        $tenant = $provision->tenant()->firstOrFail();

        $this->tagSentryScopeWithTenant($provision->slug);

        MarkTenantProvisioned::run($tenant);
    }
}
