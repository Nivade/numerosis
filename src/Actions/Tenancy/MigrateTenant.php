<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Actions\Tenancy;

use Illuminate\Database\Migrations\Migrator;
use Lorisleiva\Actions\Concerns\AsAction;
use Nvade\Numerosis\Actions\Queries\GetPendingTenantMigrations;
use Nvade\Numerosis\Models\Central\Tenant;

/**
 * Runs the outstanding tenant migrations against one tenant and reports what
 * it applied. Safe to re-run: the tenant's own migration table decides what is
 * outstanding.
 *
 * Takes a `Tenant` rather than a `TenantProvision` because most tenants have
 * no provision row — `tenancy:prune-stalled-provisions` deletes completed ones
 * after 30 days.
 *
 * The migrator runs through `Tenant::runHere()`, which ends tenancy in a
 * `finally`. stancl's own `runForMultiple()` does not, so a throwing tenant
 * there leaves the next one migrating into the wrong database.
 */
class MigrateTenant
{
    use AsAction;

    /**
     * @return list<string> The migration names applied, empty when up to date
     */
    public function handle(Tenant $tenant): array
    {
        $migrator = resolve(Migrator::class);

        $applied = $tenant->runHere(function () use ($migrator): array {
            if (! $migrator->repositoryExists()) {
                $migrator->getRepository()->createRepository();
            }

            return $migrator->run(GetPendingTenantMigrations::paths());
        });

        if (! is_array($applied)) {
            return [];
        }

        return array_values(array_map(
            static fn (string $path): string => basename($path, '.php'),
            array_filter($applied, is_string(...)),
        ));
    }
}
