<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Support\Compat\Tenancy;

/**
 * See {@see Syncable} for the mechanism. v3: `Stancl\Tenancy\Contracts\TenantWithDatabase`.
 * dev-master: `Stancl\Tenancy\Database\Contracts\TenantWithDatabase`. Both
 * declare `database(): DatabaseConfig`/`database(): Connection`-shaped
 * contracts extending `Stancl\Tenancy\Contracts\Tenant`, so this shim is a
 * plain re-export, nothing package-specific added.
 *
 * Declaration site: `src/Models/Central/Tenant.php` (`implements`). Also
 * used as a type-hint at `src/Actions/Modules/{RollbackModules,MigrateModules}.php`,
 * `src/Jobs/SeedTenantDatabase.php`, `src/Testing/CleansUpTenancyDatabases.php`
 * — those are lazy (method signatures), so they only need the `use` import
 * pointed at this shim, not a version branch of their own.
 */
if (class_exists(\Stancl\Tenancy\Enums\RouteMode::class)) {
    interface TenantWithDatabase extends \Stancl\Tenancy\Database\Contracts\TenantWithDatabase {}
} else {
    interface TenantWithDatabase extends \Stancl\Tenancy\Contracts\TenantWithDatabase {}
}
