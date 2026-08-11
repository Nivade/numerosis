<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Actions\Modules\Concerns;

use LogicException;
use Nvade\Numerosis\Exceptions\Modules\ModulesDisabled;
use Nvade\Numerosis\Features\Modules\ModuleSystemFeature;
use Nvade\Numerosis\Models\Central\Tenant;
use Nvade\Numerosis\Support\Features;

/**
 * The guards every module-billing action shares. Compose this into any new
 * one.
 *
 * These actions take a tenant *and* read the ambient one; asserting they
 * agree is what stops a charge landing on a different tenant than the one
 * that was authorised.
 */
trait GuardsModuleBilling
{
    /**
     * Asserts the action is running inside the tenant it was given.
     *
     * A `LogicException` on purpose: this is a programmer error, and must
     * reach the exception handler rather than be caught and shown to a user.
     */
    protected function assertRunningInsideTenant(Tenant $tenant): void
    {
        $currentTenant = tenant();

        throw_if(! $currentTenant instanceof Tenant || $currentTenant->getTenantKey() !== $tenant->getTenantKey(), LogicException::class, 'PurchaseModule must run inside the tenant it is purchasing for.');
    }

    /**
     * Asserts the module system is enabled. Guards purchasing only —
     * cancelling stays available so a disabled module system cannot trap
     * anyone in a subscription.
     */
    protected function assertModulesAvailable(): void
    {
        throw_unless(Features::enabled(ModuleSystemFeature::NAME), ModulesDisabled::class, 'The module marketplace is not available on this workspace.');
    }
}
