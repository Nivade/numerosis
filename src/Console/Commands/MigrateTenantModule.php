<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use InterNACHI\Modular\Support\Facades\Modules;
use Nvade\Numerosis\Models\Central\Tenant;
use Stancl\Tenancy\Concerns\HasATenantsOption;
use Stancl\Tenancy\Events\DatabaseMigrated;
use Stancl\Tenancy\Events\MigratingDatabase;

/**
 * Runs a module's tenant migrations inside each tenant.
 *
 * Enabling a module for a tenant queues this automatically; run it by hand
 * to repair a tenant whose module migrations never completed.
 */
#[Description('Run migrations for a tenant module')]
#[Signature('tenants:migrate-module {module}')]
class MigrateTenantModule extends Command
{
    use HasATenantsOption;

    public function handle(): int
    {
        $name = (string) $this->argument('module');
        $module = Modules::module($name);

        if (! $module) {
            $this->error("Module [{$name}] not found.");

            return self::FAILURE;
        }

        $path = $module->path('database/migrations/tenant');

        $this->getTenants()->each(function (mixed $tenant) use ($path): void {
            if (! $tenant instanceof Tenant) {
                return;
            }

            event(new MigratingDatabase($tenant));

            $tenant->run(function () use ($path): void {
                $this->call('migrate', [
                    '--path' => $path,
                    '--realpath' => true,
                ]);
            });

            event(new DatabaseMigrated($tenant));
        });

        return self::SUCCESS;
    }
}
