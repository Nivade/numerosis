<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use InterNACHI\Modular\Support\Facades\Modules;
use Nvade\Numerosis\Models\Central\Tenant;
use Nvade\Numerosis\Support\Compat\Tenancy\HasTenantOptions;

#[Description('Roll back migrations for a tenant module')]
#[Signature('tenants:rollback-module {module}')]
class RollbackTenantModule extends Command
{
    use HasTenantOptions;

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
