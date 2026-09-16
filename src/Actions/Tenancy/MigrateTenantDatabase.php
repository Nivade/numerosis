<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Actions\Tenancy;

use Lorisleiva\Actions\Concerns\AsAction;
use Nvade\Numerosis\Contracts\Tenancy\ProvisioningStep;
use Nvade\Numerosis\Models\Central\TenantProvision;

/**
 * Runs the tenant migrations. Safe to re-run: the migration table decides what
 * is outstanding.
 *
 * One mechanism with the fleet command, so a rollout and a new tenant cannot
 * end up at different schemas.
 */
class MigrateTenantDatabase implements ProvisioningStep
{
    use AsAction;

    public function handle(TenantProvision $provision): void
    {
        MigrateTenant::run($provision->tenant()->firstOrFail());
    }
}
