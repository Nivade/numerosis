<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Actions\Modules;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;
use InterNACHI\Modular\Support\Facades\Modules;
use InterNACHI\Modular\Support\ModuleConfig;
use Lorisleiva\Actions\Concerns\AsAction;
use RuntimeException;
use Stancl\Tenancy\Contracts\TenantWithDatabase;

/**
 * Repointed at `tenants:rollback-module` — `module:migrate-rollback` does not
 * exist in internachi/modular v3, the same class of bug MigrateModules had.
 * See .claude/plans/module-marketplace.md.
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

        /** @var Collection<int, ModuleConfig> $registered */
        $registered = Modules::modules();

        $modules = $module ?? $registered->map(fn (ModuleConfig $m): string => $m->name)->values();

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
}
