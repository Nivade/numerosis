<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Support;

use Closure;
use Illuminate\Database\Seeder;

/**
 * What a satellite package or a host has contributed to this one: routes,
 * tenant columns, tenant migration paths, seeders and permission contexts.
 * Call these through `Numerosis::add*()` and its readers, which delegate here.
 * {@see self::tenantMigrationPaths()} returns only contributed paths, where
 * {@see Numerosis::tenantMigrationPaths()} adds this package's own as well.
 *
 * @see Features::register() the same seam for feature classes
 */
final class Contributions
{
    /** @var list<string> */
    private static array $tenantColumns = [];

    /**
     * Callbacks run inside the per-domain `Route::middleware('web')
     * ->domain($domain)` group {@see Numerosis::routes()} opens for
     * `routes/web.php`, once per configured central domain. `source` is what
     * the caller of {@see self::addCentralRoutes()} passed, kept alongside the
     * closure so a contribution can be attributed.
     *
     * @var list<array{callback: Closure(): void, source: ?string}>
     */
    private static array $centralRouteCallbacks = [];

    /**
     * Callbacks run inside the single `Route::middleware('tenant')` group
     * {@see Numerosis::routes()} opens for `routes/tenant.php`. Same shape
     * as {@see self::$centralRouteCallbacks}.
     *
     * @var list<array{callback: Closure(): void, source: ?string}>
     */
    private static array $tenantRouteCallbacks = [];

    /** @var list<string> */
    private static array $tenantMigrationPaths = [];

    /** @var list<class-string<Seeder>> */
    private static array $tenantSeeders = [];

    /** @var list<class-string<Seeder>> */
    private static array $centralSeeders = [];

    /** @var list<string> */
    private static array $permissionContexts = [];

    /**
     * @param  list<string>  $columns
     */
    public static function addTenantColumns(array $columns): void
    {
        self::$tenantColumns = self::appendOnce(self::$tenantColumns, ...$columns);
    }

    /**
     * @return list<string>
     */
    public static function tenantColumns(): array
    {
        return self::$tenantColumns;
    }

    public static function addCentralRoutes(Closure $callback, ?string $source = null): void
    {
        self::$centralRouteCallbacks[] = ['callback' => $callback, 'source' => $source];
    }

    public static function addTenantRoutes(Closure $callback, ?string $source = null): void
    {
        self::$tenantRouteCallbacks[] = ['callback' => $callback, 'source' => $source];
    }

    /**
     * Read by {@see Numerosis::routes()}, which invokes each one inside the
     * group it belongs to.
     *
     * @return list<Closure(): void>
     */
    public static function centralRouteCallbacks(): array
    {
        return array_column(self::$centralRouteCallbacks, 'callback');
    }

    /**
     * @return list<Closure(): void>
     */
    public static function tenantRouteCallbacks(): array
    {
        return array_column(self::$tenantRouteCallbacks, 'callback');
    }

    /**
     * "Which package added this route": `source` is whatever the caller of
     * {@see self::addCentralRoutes()} passed, in registration order,
     * parallel to {@see self::centralRouteCallbacks()}. `null` where the
     * caller didn't attribute itself.
     *
     * @return list<?string>
     */
    public static function centralRouteSources(): array
    {
        return array_column(self::$centralRouteCallbacks, 'source');
    }

    /**
     * @return list<?string>
     */
    public static function tenantRouteSources(): array
    {
        return array_column(self::$tenantRouteCallbacks, 'source');
    }

    public static function addTenantMigrationPath(string $path): void
    {
        self::$tenantMigrationPaths = self::appendOnce(self::$tenantMigrationPaths, $path);
    }

    /**
     * Contributed paths only. {@see Numerosis::tenantMigrationPaths()} is the
     * list `HostConfig` feeds to `tenancy.migration_parameters['--path']`, and
     * includes the package's own.
     *
     * @return list<string>
     */
    public static function tenantMigrationPaths(): array
    {
        return self::$tenantMigrationPaths;
    }

    /**
     * @param  class-string<Seeder>  $seeder
     */
    public static function addTenantSeeder(string $seeder): void
    {
        /** @var list<class-string<Seeder>> $seeders */
        $seeders = self::appendOnce(self::$tenantSeeders, $seeder);

        self::$tenantSeeders = $seeders;
    }

    /**
     * @return list<class-string<Seeder>>
     */
    public static function tenantSeeders(): array
    {
        return self::$tenantSeeders;
    }

    /**
     * @param  class-string<Seeder>  $seeder
     */
    public static function addCentralSeeder(string $seeder): void
    {
        /** @var list<class-string<Seeder>> $seeders */
        $seeders = self::appendOnce(self::$centralSeeders, $seeder);

        self::$centralSeeders = $seeders;
    }

    /**
     * @return list<class-string<Seeder>>
     */
    public static function centralSeeders(): array
    {
        return self::$centralSeeders;
    }

    public static function addPermissionContext(string $context): void
    {
        self::$permissionContexts = self::appendOnce(self::$permissionContexts, $context);
    }

    /**
     * Appends whatever is not already present, preserving registration order.
     * These lists are static, so a provider registering twice would otherwise
     * grow them without bound. The route-callback lists cannot use this, since
     * two `Closure`s from the same source are distinct objects.
     *
     * @param  list<string>  $existing
     * @return list<string>
     */
    private static function appendOnce(array $existing, string ...$values): array
    {
        foreach ($values as $value) {
            if (! in_array($value, $existing, true)) {
                $existing[] = $value;
            }
        }

        return array_values($existing);
    }

    /**
     * @return list<string>
     */
    public static function permissionContexts(): array
    {
        return self::$permissionContexts;
    }

    /**
     * For tests only; a real host registers contributions once and they live
     * for the application's lifetime. Neither flush clears
     * {@see self::$tenantColumns}, whose only writer is a class declaration.
     */
    public static function flushRouteContributions(): void
    {
        self::$centralRouteCallbacks = [];
        self::$tenantRouteCallbacks = [];
    }

    /**
     * See {@see self::flushRouteContributions()}.
     */
    public static function flushMigrationAndSeederContributions(): void
    {
        self::$tenantMigrationPaths = [];
        self::$tenantSeeders = [];
        self::$centralSeeders = [];
        self::$permissionContexts = [];
    }
}
