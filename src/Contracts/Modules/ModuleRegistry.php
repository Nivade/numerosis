<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Contracts\Modules;

use Illuminate\Support\Collection;

/**
 * `InteractsWithTenantModules::getEnabledModuleNames()` queries the tenant
 * `modules` table directly today — orphaned since the clients module that
 * consumed it was removed (`.claude/rules/module-marketplace.md`), but still
 * the only mechanism for "is module X enabled for the current tenant,"
 * needed by any future Filament plugin registered conditionally on a
 * module. This contract is what the package talks to instead of the six
 * concrete `nvade/*` modules directly — it must serve thin-app's modules
 * without the package ever knowing their names.
 */
interface ModuleRegistry
{
    /**
     * @return Collection<int, string> enabled module slugs for the current tenant
     */
    public function enabledModuleNames(): Collection;

    public function isEnabled(string $moduleName): bool;
}
