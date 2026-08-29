<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Support\Compat\Tenancy;

/**
 * See {@see Syncable} for the mechanism. v3: `Stancl\Tenancy\Contracts\SyncMaster`.
 * dev-master: `Stancl\Tenancy\ResourceSyncing\SyncMaster` — which extends
 * dev-master's own `Syncable`, not this shim's, so this file does not
 * `extends Syncable` itself; each version's `SyncMaster` already carries
 * its own version's `Syncable` in its inheritance chain.
 * Declaration site: `src/Contracts/Auth/CentralUserModel.php`.
 */
if (class_exists(\Stancl\Tenancy\Enums\RouteMode::class)) {
    interface SyncMaster extends \Stancl\Tenancy\ResourceSyncing\SyncMaster {}
} else {
    interface SyncMaster extends \Stancl\Tenancy\Contracts\SyncMaster {}
}
