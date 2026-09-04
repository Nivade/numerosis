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
 * {@see self::tenantMigrationPaths()} returns only contributed paths.
 * {@see Numerosis::tenantMigrationPaths()} adds this package's own path on
 * top of that. Use the second one when you need every path tenancy should
 * migrate; `HostConfig` does.
 */
final class Contributions
{
    /** @var list<string> */
    private static array $tenantColumns = [];

    /**
     * Callbacks run inside the per-domain `Route::middleware('web')
     * ->domain($domain)` group {@see Numerosis::routes()} opens for
     * `routes/web.php`, once per configured central domain. `source` is
     * whatever the caller of {@see self::addCentralRoutes()} passed — a
     * package name by convention, `null` if they didn't say — kept alongside
     * the closure so a contribution can be attributed, not just counted.
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
     * "Which package added this route" — `source` is whatever the caller of
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
     * Appends whatever is not already present, preserving registration
     * order. Every list here is static and therefore lives as long as the
     * process does, so a provider that registers more than once — Octane's
     * per-worker boot, a host provider re-registered by a test harness —
     * would otherwise grow them without bound and hand `tenancy.migration_parameters`
     * the same path several times over.
     *
     * The route-callback lists cannot be deduplicated the same way: two
     * `Closure`s built from the same `function () { … }` on two boots are
     * distinct objects with nothing comparable about them, and collapsing by
     * `source` alone would drop a package's second, legitimately different
     * contribution. They are bounded in practice by there being one
     * registration site per package.
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
