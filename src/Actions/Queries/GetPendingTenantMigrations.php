<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Actions\Queries;

use Illuminate\Database\Migrations\Migrator;
use Illuminate\Support\Facades\Config;
use Lorisleiva\Actions\Concerns\AsAction;
use Nvade\Numerosis\Models\Central\Tenant;

/**
 * Which tenant migrations this tenant has not run. Answers "is the fleet
 * consistent" without changing anything.
 *
 * `Migrator::pendingMigrations()` is protected, so the diff happens here.
 */
class GetPendingTenantMigrations
{
    use AsAction;

    /**
     * @return list<string> Migration names, in the order they would run
     */
    public function handle(Tenant $tenant): array
    {
        $migrator = resolve(Migrator::class);

        $pending = $tenant->runHere(function () use ($migrator): array {
            $files = $migrator->getMigrationFiles(self::paths());

            // A database created but never migrated has no `migrations`
            // table, and reading the ran list there throws rather than
            // answering "none".
            $ran = $migrator->repositoryExists() ? $migrator->getRepository()->getRan() : [];

            return array_values(array_diff(array_keys($files), $ran));
        });

        return is_array($pending) ? array_values(array_filter($pending, is_string(...))) : [];
    }

    /**
     * Every path tenancy migrates, host contributions included:
     * `Boot\HostConfig` merges the package's own into this key, so reading it
     * is what keeps a host's migrations from being invisible to the fleet.
     *
     * @return list<string>
     */
    public static function paths(): array
    {
        $parameters = Config::array('tenancy.migration_parameters', []);
        $paths = $parameters['--path'] ?? [];

        return is_array($paths)
            ? array_values(array_filter($paths, is_string(...)))
            : [];
    }
}
