<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Actions\Tenancy;

use Lorisleiva\Actions\Concerns\AsAction;
use Nvade\Numerosis\Concerns\Tenancy\TagsSentryScopeWithTenant;
use Nvade\Numerosis\Contracts\Tenancy\ProvisioningStep;
use Nvade\Numerosis\Models\Central\TenantProvision;

/**
 * Signals that provisioning finished. Configured last because it is what
 * reports the tenant ready. The chain's one terminal handler covers failure
 * here, as it does for every other step.
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
