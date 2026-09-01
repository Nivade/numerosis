<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Support;

use Closure;
use Illuminate\Database\Seeder;

/**
 * What a satellite package or a host has contributed to this one: routes,
 * tenant columns, tenant migration paths, seeders and permission contexts.
 *
 * Split out of {@see Numerosis} on 2026-09-01, the second cut after
 * {@see ModelResolver} and for the same reason — that class spanned five
 * unrelated audiences. This is the *contribution seam* audience, and it is the
 * one with a shared shape: every entry here is `add*()` writes, a reader
 * returns, and nothing consults config. `Features::register()`/`::registered()`
 * is the same seam for feature classes and deliberately stays on
 * {@see Features}, next to the config-backed list it merges with.
 *
 * Every `Numerosis::add*()` and its reader still exists and delegates here;
 * satellites and hosts keep calling `Numerosis::`, which is the documented
 * entry point (`docs/extending.md`). Moving the implementation is the point,
 * renaming the seam is not.
 *
 * **Readers return contributions only, never the package's own.** The one
 * place that distinction bites is migration paths:
 * {@see self::tenantMigrationPaths()} answers "what did other packages add",
 * while {@see Numerosis::tenantMigrationPaths()} answers "every path tenancy
 * should migrate", the package's own {@see Numerosis::tenantMigrationPath()}
 * included. `HostConfig` wants the second one.
 */
final class Contributions
{
    /** @var list<string> */
    private static array $tenantColumns = [];

    /**
     * Callbacks run inside the per-domain `Route::middleware('web')
     * ->domain($domain)` group {@see Numerosis::routes()} opens for
     * `routes/web.php`, once per configured central domain.
     *
     * @var list<Closure(): void>
     */
    private static array $centralRouteCallbacks = [];

    /**
     * Callbacks run inside the single `Route::middleware('tenant')` group
     * {@see Numerosis::routes()} opens for `routes/tenant.php`.
     *
     * @var list<Closure(): void>
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
        self::$tenantColumns = array_values(array_merge(self::$tenantColumns, $columns));
    }

    /**
     * @return list<string>
     */
    public static function tenantColumns(): array
    {
        return self::$tenantColumns;
    }

    public static function addCentralRoutes(Closure $callback): void
    {
        self::$centralRouteCallbacks[] = $callback;
    }

    public static function addTenantRoutes(Closure $callback): void
    {
        self::$tenantRouteCallbacks[] = $callback;
    }

    /**
     * Read by {@see Numerosis::routes()}, which invokes each one inside the
     * group it belongs to.
     *
     * These are bare closures, so this answers "how many contributions" and
     * never "which package made them" — see the routes bullet in
     * `.claude/rules/package-boundaries.md` for why attributing them means
     * changing the writer's signature rather than adding a reader.
     *
     * @return list<Closure(): void>
     */
    public static function centralRouteCallbacks(): array
    {
        return self::$centralRouteCallbacks;
    }

    /**
     * @return list<Closure(): void>
     */
    public static function tenantRouteCallbacks(): array
    {
        return self::$tenantRouteCallbacks;
    }

    public static function addTenantMigrationPath(string $path): void
    {
        self::$tenantMigrationPaths[] = $path;
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
        self::$tenantSeeders[] = $seeder;
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
        self::$centralSeeders[] = $seeder;
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
        self::$permissionContexts[] = $context;
    }

    /**
     * @return list<string>
     */
    public static function permissionContexts(): array
    {
        return self::$permissionContexts;
    }

    /**
     * For tests only — a real host or satellite registers contributions once,
     * from a service provider, and they live for the application's lifetime.
     *
     * Kept as two methods rather than one `flush()`, mirroring
     * `Numerosis::resetRouteContributionsForTesting()` and
     * `::resetMigrationAndSeederContributionsForTesting()` exactly. Collapsing
     * them would widen what each caller clears, and `PackageContributionSeamsTest`
     * calls them separately.
     *
     * **Neither clears {@see self::$tenantColumns}**, which has no reset at
     * all and never did: the only writer is `Models\Central\Tenant`'s own
     * declaration, so there is no per-test registration to undo.
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
