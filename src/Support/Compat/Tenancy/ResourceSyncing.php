<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Support\Compat\Tenancy;

/**
 * See {@see Syncable} for the mechanism, and {@see \Nvade\Numerosis\Support\Compat\LogsActivityIfInstalled}
 * for the same shape applied to a trait rather than an interface. v3:
 * `Stancl\Tenancy\Database\Concerns\ResourceSyncing`. dev-master:
 * `Stancl\Tenancy\ResourceSyncing\ResourceSyncing`.
 *
 * Declaration sites: `src/Models/Tenant/User.php`, `src/Models/Central/CentralUser.php`.
 */
if (class_exists(\Stancl\Tenancy\Enums\RouteMode::class)) {
    trait ResourceSyncing
    {
        use \Stancl\Tenancy\ResourceSyncing\ResourceSyncing;
    }
} else {
    trait ResourceSyncing
    {
        use \Stancl\Tenancy\Database\Concerns\ResourceSyncing;
    }
}
