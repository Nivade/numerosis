<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Actions\Tenancy;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Config;
use Lorisleiva\Actions\Concerns\AsAction;
use Nvade\Numerosis\Concerns\Tenancy\RunsInTenant;
use Nvade\Numerosis\Contracts\Tenancy\ProvisioningStep;
use Nvade\Numerosis\Database\Seeders\TenantDatabaseSeeder;
use Nvade\Numerosis\Models\Central\TenantProvision;
use RuntimeException;
use Throwable;

/**
 * Seeds a new tenant database.
 *
 * It invokes the seeder directly rather than calling `tenants:seed`, which
 * does not work: with stancl/tenancy installed that command registers under
 * the wrong name and drops its own `--tenants` option.
 *
 * This was a plain Job until the pipeline stopped going through
 * `Stancl\JobPipeline`, whose `new $job($tenant)` plus zero-argument `handle()`
 * convention it had to match. Nothing owns that convention now.
 */
class SeedTenantDatabase implements ProvisioningStep
{
    use AsAction;
    use RunsInTenant;

    public function handle(TenantProvision $provision): void
    {
        $tenant = $provision->tenant()->firstOrFail();

        $this->runInTenant($tenant, function () use ($provision): void {
            try {
                Model::unguarded(function (): void {
                    resolve(Config::string('numerosis.tenancy.seeder', TenantDatabaseSeeder::class))
                        ->setContainer(app())
                        ->__invoke();
                });
            } catch (Throwable $e) {
                throw new RuntimeException("Seeding failed for tenant {$provision->slug}: {$e->getMessage()}", 0, previous: $e);
            }
        });
    }
}
