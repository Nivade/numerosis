<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use InterNACHI\Modular\Support\Facades\Modules;
use Nvade\Numerosis\Models\Central\Tenant;
use Stancl\Tenancy\Concerns\HasATenantsOption;

/**
 * Runs a module's permission seeder inside each tenant.
 *
 * Module permissions are seeded rather than migrated, so re-running this
 * repairs a tenant without a rollback, and adding a permission needs no new
 * migration.
 */
#[Description('Run a module\'s permission seeder for a tenant')]
#[Signature('tenants:seed-module {module}')]
class SeedTenantModule extends Command
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

        $class = Str::studly($name).'PermissionSeeder';

        $this->getTenants()->each(function (mixed $tenant) use ($name, $class): void {
            if (! $tenant instanceof Tenant) {
                return;
            }

            $tenant->run(function () use ($name, $class): void {
                $this->call('db:seed', [
                    '--module' => $name,
                    '--class' => $class,
                    '--force' => true,
                ]);
            });
        });

        return self::SUCCESS;
    }
}
