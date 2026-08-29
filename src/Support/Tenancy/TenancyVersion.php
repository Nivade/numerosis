<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Support\Tenancy;

/**
 * The single place this package decides whether the installed
 * `stancl/tenancy` is the v3 line or `dev-master` ("v4") — every
 * `Support\Compat\Tenancy\*` shim and {@see TenancyConfigKeys}
 * ask this instead of re-deriving the check.
 *
 * There is no v4 tag and `dev-master` has no `branch-alias`, so a
 * `composer.lock`/`InstalledVersions` version string is not comparable —
 * a dev-master install reports a branch name, not a version. The only
 * reliable signal is a symbol that exists on one line and not the other:
 * v3 has no `Enums` namespace at all, so `RouteMode`'s presence is a clean
 * boolean. See `.claude/rules/stancl-tenancy-v4.md`.
 */
final class TenancyVersion
{
    private static ?bool $isDevMaster = null;

    public static function isDevMaster(): bool
    {
        return self::$isDevMaster ??= class_exists(\Stancl\Tenancy\Enums\RouteMode::class);
    }

    /**
     * `::class` on an unimported FQCN does not autoload the class — it is a
     * compile-time string constant — so these four are plain name
     * resolution, not the eager-declaration problem the `Support\Compat\Tenancy\*`
     * shims exist for. Renamed, not just moved: `PreventAccessFromCentralDomains`
     * -> `PreventAccessFromUnwantedDomains`.
     */
    public static function preventAccessFromCentralDomainsMiddleware(): string
    {
        return self::isDevMaster()
            ? \Stancl\Tenancy\Middleware\PreventAccessFromUnwantedDomains::class
            : \Stancl\Tenancy\Middleware\PreventAccessFromCentralDomains::class;
    }

    public static function uuidGeneratorClass(): string
    {
        return self::isDevMaster()
            ? \Stancl\Tenancy\UniqueIdentifierGenerators\UUIDGenerator::class
            : \Stancl\Tenancy\UUIDGenerator::class;
    }

    /**
     * The class `Providers\TenancyServiceProvider`'s event map must key against.
     *
     * @return class-string
     */
    public static function syncedResourceSavedEventClass(): string
    {
        return self::isDevMaster()
            ? \Stancl\Tenancy\ResourceSyncing\Events\SyncedResourceSaved::class
            : \Stancl\Tenancy\Events\SyncedResourceSaved::class;
    }

    /**
     * Renamed, not just moved: `...ChangedInForeignDatabase` -> `...SavedInForeignDatabase`.
     *
     * @return class-string
     */
    public static function syncedResourceChangedInForeignDatabaseEventClass(): string
    {
        return self::isDevMaster()
            ? \Stancl\Tenancy\ResourceSyncing\Events\SyncedResourceSavedInForeignDatabase::class
            : \Stancl\Tenancy\Events\SyncedResourceChangedInForeignDatabase::class;
    }
}
