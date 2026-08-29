<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Support\Compat\Tenancy;

/**
 * `implements`/`use trait` clauses resolve their target eagerly, at class
 * declaration time — see {@see \Nvade\Numerosis\Support\Compat\FilamentUserContract}
 * for the mechanism this shares. Unlike that shim, both v3 and v4 always
 * have *some* form of this symbol (stancl/tenancy is a hard dependency,
 * never optional) — the branch here picks which namespace, not whether one
 * exists. v3: `Stancl\Tenancy\Contracts\Syncable`. dev-master:
 * `Stancl\Tenancy\ResourceSyncing\Syncable`. Declaration sites:
 * `src/Contracts/Auth/TenantUserModel.php`, `src/Models/User.php`.
 */
if (class_exists(\Stancl\Tenancy\Enums\RouteMode::class)) {
    interface Syncable extends \Stancl\Tenancy\ResourceSyncing\Syncable {}
} else {
    interface Syncable extends \Stancl\Tenancy\Contracts\Syncable {}
}
