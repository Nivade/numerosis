<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Features\Modules;

use Nvade\Numerosis\Contracts\NamedFeature;

/**
 * The whole per-tenant module system: the Filament plugins registered by
 * TenantAdminPanelProvider::enabledModulePlugins(), the tenants:*-module
 * Artisan commands, the self-serve Marketplace page/ModuleResource, and
 * PurchaseModule's own gate. Remove this class from
 * config('numerosis.features') and a deployment runs no modules at all — no
 * storefront, no admin-provisioned modules, nothing.
 *
 * There is deliberately no separate "marketplace" switch nesting under this
 * one. That two-switch design existed briefly and was collapsed back into a
 * single flag at the user's request (2026-08-04): a deployment either runs
 * modules or it does not, and splitting "runs modules" from "can buy modules"
 * was indirection nothing needed.
 *
 * bootstrap() is deliberately empty — nothing about modules is registered at
 * boot. Every consumer asks Features::enabled(self::NAME) at call time.
 * Presence in the features array is the whole toggle.
 *
 * Purchased `modules` rows and every module's tenant tables are untouched by
 * disabling this; the module system is switched off, not uninstalled.
 */
class ModuleSystemFeature implements NamedFeature
{
    public const NAME = 'modules';

    public static function featureName(): string
    {
        return self::NAME;
    }

    public function bootstrap(): void
    {
        // Nothing to register — see the class docblock.
    }
}
