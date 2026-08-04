<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Support\Defaults;

use Illuminate\Support\Collection;
use Nvade\Numerosis\Concerns\InteractsWithTenantModules;
use Nvade\Numerosis\Contracts\Modules\ModuleRegistry;

/**
 * Thin wrapper around InteractsWithTenantModules — that trait's request-scoped
 * memoisation and the pre-tenancy-bootstrap fallback query (Filament registers
 * plugins before bootstrappers run) are real, tested behaviour, not
 * duplicated here.
 */
class EloquentModuleRegistry implements ModuleRegistry
{
    use InteractsWithTenantModules;

    /**
     * @return Collection<int, string>
     */
    public function enabledModuleNames(): Collection
    {
        return $this->getEnabledModuleNames();
    }

    public function isEnabled(string $moduleName): bool
    {
        return $this->isModuleEnabled($moduleName);
    }
}
