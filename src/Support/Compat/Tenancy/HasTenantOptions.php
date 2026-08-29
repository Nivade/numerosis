<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Support\Compat\Tenancy;

/**
 * See {@see Syncable} for the mechanism. v3: `Stancl\Tenancy\Concerns\HasATenantsOption`
 * (`getTenants()`, no-arg `__construct()`). dev-master:
 * `Stancl\Tenancy\Concerns\HasTenantOptions` (`getTenants(?array $tenantKeys = null)`,
 * `__construct(mixed ...$args)`, adds `--skip-tenants`/`--with-pending` and a
 * `getTenantsQuery()` method) — not a straight rename, a real signature
 * change. Safe here only because
 * `src/Console/Commands/{Seed,Migrate,Rollback}TenantModule.php` declare no
 * constructor and no `getTenants()` override of their own — see
 * `.claude/rules/stancl-tenancy-v4.md`. **A future command overriding either
 * member must check the real trait's signature on the version it's written
 * against before doing so — one compiles clean on one version and fatals on
 * the other.**
 */
if (class_exists(\Stancl\Tenancy\Enums\RouteMode::class)) {
    trait HasTenantOptions
    {
        use \Stancl\Tenancy\Concerns\HasTenantOptions;
    }
} else {
    trait HasTenantOptions
    {
        use \Stancl\Tenancy\Concerns\HasATenantsOption;
    }
}
