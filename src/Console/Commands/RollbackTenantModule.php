<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Nvade\Numerosis\Concerns\ResolvesInstalledModules;
use Nvade\Numerosis\Models\Central\Tenant;
use Stancl\Tenancy\Concerns\HasATenantsOption;

#[Description('Roll back migrations for a tenant module')]
#[Signature('tenants:rollback-module {module}')]
class RollbackTenantModule extends Command
{
    use HasATenantsOption;
    use ResolvesInstalledModules;

    public function handle(): int
    {
        $module = $this->installedModule((string) $this->argument('module'));

        if (! $module) {
            return self::FAILURE;
        }

        $path = $module->path('database/migrations/tenant');

        $this->getTenants()->each(function (mixed $tenant) use ($path): void {
            if (! $tenant instanceof Tenant) {
                return;
            }

            $tenant->run(function () use ($path): void {
                $this->call('migrate:rollback', [
                    '--path' => $path,
                    '--realpath' => true,
                ]);
            });
        });

        return self::SUCCESS;
    }
}
