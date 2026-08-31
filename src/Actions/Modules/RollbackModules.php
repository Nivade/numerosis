<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Actions\Modules;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;
use InterNACHI\Modular\Support\Facades\Modules;
use InterNACHI\Modular\Support\ModuleConfig;
use Lorisleiva\Actions\Concerns\AsAction;
use Nvade\Numerosis\Features\Modules\ModuleSystemFeature;
use RuntimeException;
use Stancl\Tenancy\Contracts\TenantWithDatabase;

/**
 * Rolls back a module's tenant migrations, dropping its tables.
 *
 * Destructive, and deliberately not called by module cancellation — removing
 * a tenant's data is a separate decision.
 */
class RollbackModules implements ShouldQueue
{
    use AsAction;

    /**
     * @param  Collection<int, string>|string|null  $module
     */
    public function handle(TenantWithDatabase $tenant, null|string|Collection $module = null): void
    {
        if (is_string($module)) {
            $module = new Collection([$module]);
        }

        $modules = $module ?? $this->registeredModuleNames();

        $modules->each(function (string $module) use ($tenant): void {
            $exitCode = Artisan::call('tenants:rollback-module', [
                'module' => $module,
                '--tenants' => [$tenant->getTenantKey()],
            ]);

            if ($exitCode !== 0) {
                throw new RuntimeException("tenants:rollback-module failed for module {$module} on tenant {$tenant->getTenantKey()}: ".Artisan::output());
            }
        });
    }

    /**
     * Every module installed on this node, or none when there is no registry
     * to ask. An explicit module list still rolls back either way — the
     * migrations being reverted belong to the tenant's database, not to the
     * registry.
     *
     * @return Collection<int, string>
     */
    private function registeredModuleNames(): Collection
    {
        if (! ModuleSystemFeature::available()) {
            return new Collection;
        }

        /** @var Collection<int, ModuleConfig> $registered */
        $registered = Modules::modules();

        return $registered->map(fn (ModuleConfig $module): string => $module->name)->values();
    }
}
