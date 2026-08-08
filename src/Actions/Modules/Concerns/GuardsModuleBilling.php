<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Actions\Modules\Concerns;

use LogicException;
use Nvade\Numerosis\Exceptions\Modules\ModulesDisabled;
use Nvade\Numerosis\Features\Modules\ModuleSystemFeature;
use Nvade\Numerosis\Models\Central\Tenant;
use Nvade\Numerosis\Support\Features;

/**
 * See .claude/rules/module-marketplace.md — CancelModule was once missing
 * assertRunningInsideTenant(), which this trait exists to stop happening
 * again for any future module-billing action.
 */
trait GuardsModuleBilling
{
    /**
     * Programmer error, not a domain refusal — this must stay a
     * LogicException so it escapes to the handler with full context rather
     * than being caught by a UI `catch (ShowsMessageToUser $e)` block
     * (.claude/rules/exception-handling.md).
     */
    protected function assertRunningInsideTenant(Tenant $tenant): void
    {
        $currentTenant = tenant();

        throw_if(! $currentTenant instanceof Tenant || $currentTenant->getTenantKey() !== $tenant->getTenantKey(), LogicException::class, 'PurchaseModule must run inside the tenant it is purchasing for.');
    }

    /**
     * A domain refusal with customer-facing copy — PurchaseModule only.
     * CancelModule deliberately does not call this: it never gated on the
     * modules feature at all, only on tenant identity, so disabling the
     * module system does not block someone from cancelling a row they can
     * still see through means other than the (now-hidden) UI.
     */
    protected function assertModulesAvailable(): void
    {
        throw_unless(Features::enabled(ModuleSystemFeature::NAME), ModulesDisabled::class, 'The module marketplace is not available on this workspace.');
    }
}
