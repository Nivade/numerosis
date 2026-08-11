<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Contracts\Modules;

use Illuminate\Support\Collection;

/**
 * Answers which modules the current tenant has enabled, without the package
 * knowing any module by name.
 *
 * Use it to register a Filament plugin or other behaviour conditionally on a
 * module being enabled, or point `numerosis.tenancy.implementations` at your own
 * class to source that answer differently.
 */
interface ModuleRegistry
{
    /**
     * @return Collection<int, string> enabled module slugs for the current tenant
     */
    public function enabledModuleNames(): Collection;

    public function isEnabled(string $moduleName): bool;
}
