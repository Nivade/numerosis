<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Actions\Modules;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Artisan;
use Lorisleiva\Actions\Concerns\AsAction;
use Nvade\Numerosis\Exceptions\Modules\ModulesDisabled;
use Nvade\Numerosis\Features\Modules\ModuleSystemFeature;
use Nvade\Numerosis\Models\Tenant\Module;
use Nvade\Numerosis\Support\Numerosis;
use RuntimeException;
use Stancl\Tenancy\Contracts\TenantWithDatabase;

class MigrateModules implements ShouldQueue
{
    use AsAction;

    /**
     * Migrate then seed, in that order — the permission seeder is written
     * against the module's own schema.
     */
    public function handle(TenantWithDatabase $tenant, string $module): void
    {
        // The two commands below are only registered when the module registry
        // is installed, so without this the failure is a bare
        // CommandNotFoundException from inside a queued job. Loud on purpose:
        // silently stamping `migrated_at` would claim tables exist that do
        // not, and silently doing nothing would strand a paid purchase.
        throw_unless(ModuleSystemFeature::available(), ModulesDisabled::class, 'The module system is not available on this installation.');

        $migrateExitCode = Artisan::call('tenants:migrate-module', [
            'module' => $module,
            '--tenants' => [$tenant->getTenantKey()],
        ]);

        if ($migrateExitCode !== 0) {
            throw new RuntimeException("tenants:migrate-module failed for tenant {$tenant->getTenantKey()}: ".Artisan::output());
        }

        $seedExitCode = Artisan::call('tenants:seed-module', [
            'module' => $module,
            '--tenants' => [$tenant->getTenantKey()],
        ]);

        if ($seedExitCode !== 0) {
            throw new RuntimeException("tenants:seed-module failed for tenant {$tenant->getTenantKey()}: ".Artisan::output());
        }

        // The only writer of this column — it's the "actually migrated",
        // not just "enabled", signal a module's own code (e.g. the branding
        // module's ApplyBranding middleware) and every module resource assume
        // when they query their own tables.
        $moduleClass = Numerosis::model(Module::class);

        $tenant->run(function () use ($module, $moduleClass): void {
            $moduleClass::where('name', $module)->update(['migrated_at' => now()]);
        });
    }
}
